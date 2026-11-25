<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts, OfferState, Retail, StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Catalogue\Feed\OfferWork;
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferTerms;

use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,PricingRule};

/** The staff next step for one TLD action and term. Recovery only hints the existing read queue. */
final class NextSteps
{
    private const NAMES=['register'=>'registration','renew'=>'renewal','transfer'=>'transfer'];
    public function __construct(private readonly StaffCatalogue $catalogue,private readonly Clock $clock,private readonly ?OfferWork $work=null) {}
    public function next(Tld $t,array $offers,string $action,int $term,array &$once,bool $queueRecovery): array
    {
        $offer=$offers[$action.':'.$term]??null;$reason=$offer?->decision->reason;$now=$this->clock->now();
        if(!$offers){return self::missing('Check saved settings on .'.$t->tld.'.');}
        if($reason==='account_unavailable'){return self::missing('Check account setup on Registrar account.','account');}
        try{$rule=StaffCatalogue::ruleOnce($t,$once);}catch(\DomainException|\InvalidArgumentException){return self::missing('Fix the pricing rule in Edit prices on .'.$t->tld.'.');}
        if($reason==='staff_term_stopped'&&$action==='register'){return self::step('off','Use Edit terms in What you sell to change the longest registration term.','tld');}
        if($reason==='staff_term_stopped'&&$action==='transfer'){return self::step('off','Transfers are off. Use Edit terms in What you sell to change that choice.','tld');}
        if(in_array($reason,StaffCatalogue::STARTS,true)){return $this->stopped($t,$action,$once);}
        // Account reads and queue hints are per account, so the rows of one page share them.
        $server=(int)$t->server_id;
        $binding=static function()use($server,&$once){return $once['binding'][$server]??=AccountBindings::binding(Account::where('server_id',$server)->sole());};
        $readable=function($b)use($server,&$once):bool {return $once['readable'][$server]??=$this->catalogue->readable($b);};
        if($reason==='publication_pending'&&$this->work){return $this->publication($binding,$server,$once,$queueRecovery,$now);}
        try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){return self::missing('Check saved settings on .'.$t->tld.'.');}
        $human=null;
        if(!$facts||!$facts->fresh($now)||$t->registrar_key==='namesilo'
            &&!OfferState::testFactsAccepted($binding(),$t->tld,$facts,$this->clock)){
            [$step,$human]=$this->terms($t,$binding,$readable,$server,$once,$queueRecovery,$now);
            if($step!==null){return $step;}
        }
        $expiredRefusal=static function(string $key)use($offers,$now):bool {
            $cost=($offers[$key]??null)?->observation();
            return $cost!==null&&!$cost->enabled&&!$cost->fresh($now);
        };
        if($facts?->fresh($now)&&!in_array($term,$facts->terms[$action]??[],true)&&!$expiredRefusal($action.':'.$term)){return self::step('off','Not offered by this registrar for this term.');}
        if($facts?->fresh($now)&&!in_array(1,$facts->terms['renew']??[],true)&&!$expiredRefusal('renew:1')){return self::step('off','This registrar does not offer annual renewal for this TLD.');}
        if($reason==='unsupported_facts'){$human=self::missing(self::unsupportedNext($t,$facts),'tld');}
        $cost=$this->costs($t,$offers,$action,$term,$rule,$binding,$readable,$server,$once,$queueRecovery,$now);
        if($cost!==null){return $cost;}
        if($human!==null){return $human;}
        return match($reason){
            'seller_changed'=>($once['seller_rights']??=TldFacts::canChangeSeller())?self::missing(self::sellerStep($t,$action)):self::missing(TldFacts::ASK_SELLER,null),
            'below_cost'=>self::missing('Raise the price in Edit prices on .'.$t->tld.'.'),
            'cost_limit'=>self::missing('Change the cost limit under Default for new TLDs in What you sell.',null),
            'unpublished_price'=>self::missing('Set a positive '.self::NAMES[$action].' price in Edit prices on .'.$t->tld.'.'),
            default=>self::step('ready',''),
        };
    }
    /** A staff stop: Start selling lifts it unless the seller or the registrar terms block it. */
    private function stopped(Tld $t,string $action,array &$once): array
    {
        try{$start=$this->catalogue->start($t);}catch(\Throwable){return self::missing('Check saved settings on .'.$t->tld.'.');}
        if($start['actions']===[]||$action!=='renew'&&!in_array($action,$start['actions'],true)){
            return ($once['seller_rights']??=TldFacts::canChangeSeller())
                ?self::step('off',self::sellerStep($t,$action),'tld'):self::step('off',TldFacts::ASK_SELLER);
        }
        return match($start['block']){null=>self::step('off','Use Start selling on .'.$t->tld.'.','tld'),
            'unsupported_facts'=>self::missing(self::unsupportedNext($t),'tld'),
            default=>self::step('off','Not offered by this registrar for this term.')};
    }
    private function publication(\Closure $binding,int $server,array &$once,bool $queueRecovery,\DateTimeImmutable $now): array
    {
        if(!isset($once['publication'][$server])){
            $b=$binding();
            if($queueRecovery){$this->work->wake($b);}
            $status=$this->work->status($b);
            // Local publication is due on the next turn, independently of the feed date.
            unset($status['due_at']);$once['publication'][$server]=$status;
        }
        $status=$once['publication'][$server];
        return $queueRecovery||!empty($status['local_failure'])?self::waiting($status,$now):self::missing('Prices are waiting to be published.');
    }
    /** @return array{0:?array,1:?array} a step to return now, or a step to show unless a cost step comes first */
    private function terms(Tld $t,\Closure $binding,\Closure $readable,int $server,array &$once,bool $queueRecovery,\DateTimeImmutable $now): array
    {
        if(!$this->work||!$readable($b=$binding())){return [self::missing('Check account setup on Registrar account.','account'),null];}
        if($queueRecovery){$this->work->requestFacts($b,$t->tld);}
        $status=$once['terms'][$server][$t->tld]??=$this->work->status($b,$t->tld);
        if($status['state']==='waiting_for_evidence'){return [self::missing(TldFacts::TERMS_EVIDENCE_MISSING.'.'),null];}
        if(!$queueRecovery&&$status['state']==='unknown'){return [null,self::missing('Registrar terms not read yet.')];}
        if($status['state']==='unsupported'){return [null,self::missing('Ask the registrar to confirm terms for .'.$t->tld.'.',null)];}
        return [self::waiting($status,$now),null];
    }
    /** The first needed cost that is missing, old or refused, with the read that would fix it. */
    private function costs(Tld $t,array $offers,string $action,int $term,array $rule,\Closure $binding,\Closure $readable,int $server,array &$once,bool $queueRecovery,\DateTimeImmutable $now): ?array
    {
        $costs=StaffCatalogue::costs($offers);
        foreach(Retail::terms($action,$term) as [$needed,$years]){
            $key=$needed.':'.$years;
            $cost=$offers[$key]??null;$observation=$cost?->observation();
            if($observation&&$observation->fresh($now)&&!$observation->enabled){return self::step('off','Not offered by this registrar for this term.');}
            if(!$observation||!$observation->fresh($now)||$cost->staffRetail()===null
                ||$key==='renew:1'&&OfferState::money($t->catalogue_retained_annual_retail,2)===null){
                if($observation?->fresh($now)){
                    $quote=Retail::quote($observation,$rule,$costs);
                    if($quote===null||PricingRule::units($quote,2)<1){return self::missing('Set a positive '.self::NAMES[$observation->action].' price in Edit prices on .'.$t->tld.'.');}
                }
                if(!$this->work||!$readable($b=$binding())){return self::missing('Check account setup on Registrar account.','account');}
                $status=$once['synced'][$server]??$once['status'][$server]??=$this->work->status($b);
                if(!$observation&&($status['succeeded_at']??0)>$now->getTimestamp()-86400&&empty($status['remaining'])&&self::readSinceSelected($t,(int)$status['succeeded_at'],$once)){
                    [$needed,$years]=explode(':',$key);$name=self::NAMES[$needed];
                    $at=\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((int)$status['succeeded_at']);
                    return self::missing('The registrar lists no '.$years.'-year '.$name.' cost. Prices updated '.$at.'.',null);
                }
                if(!$queueRecovery){
                    if(!empty($status['failure'])){return self::missing(TldFacts::syncCause($status['failure']).'.','account');}
                    return isset($status['due_at'])||in_array($status['state'],['queued','running','retrying'],true)
                        ?self::waiting($status,$now):self::missing('No current registrar cost for this term.');
                }
                if(!isset($once['synced'][$server])){$this->work->sync($b,RequestOrigin::Background);$once['synced'][$server]=$this->work->status($b);}
                return self::waiting($once['synced'][$server],$now);
            }
        }
        return null;
    }
    private static function step(string $state,string|HtmlString $next,?string $open=null): array { return compact('state','next','open'); }
    private static function missing(string $next,?string $open='tld'): array { return self::step('missing',$next,$open); }
    private static function waiting(array $s,\DateTimeImmutable $now): array
    {
        return !empty($s['local_failure'])?self::missing('Last registrar read failed. See the registrar account.','account'):
            self::step('checking',isset($s['due_at'])&&$s['due_at']>=$now->getTimestamp()&&empty($s['remaining'])?self::nextCheck((int)$s['due_at']):'Registrar check queued.');
    }
    /** The next step for a registrar requirement checkout can't meet: name it, and sell through another registrar. */
    private static function unsupportedNext(Tld $t,?Facts $facts=null): string
    {
        if($facts===null){try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){$facts=null;}}
        return 'This registrar '.OfferTerms::unsupportedCause($facts,(string)$t->registrar_key,(string)$t->tld).' for .'.$t->tld.'. '.(TldFacts::canChangeSeller()?TldFacts::SELL_ELSEWHERE:TldFacts::ASK_SELLER);
    }
    /** When the queued read runs, in the viewer's time zone like the other staff times. */
    private static function nextCheck(int $at): HtmlString
    { return new HtmlString(Sheet::moment($at,'Next check')->toHtml().'.'); }
    /** An absent cost is the registrar's answer only when the feed was read after this TLD's seller was last saved. */
    private static function readSinceSelected(Tld $t,int $succeededAt,array &$once): bool
    {
        // One read of the seller save times serves every row on the page.
        $saved=($once['seller_saved']??=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::pluck('updated_at','tld')->all())[$t->tld]??null;
        return TldFacts::feedConfirmed(['selected_at'=>$saved?\Carbon\CarbonImmutable::parse($saved)->getTimestamp():null,'succeeded_at'=>$succeededAt]);
    }
    /** This account is already the chosen registrar but its seller row has the action off: name that switch. */
    private static function sellerStep(Tld $t,string $action): string
    {
        $p=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->first();
        $chosen=$p&&(int)$p->server_id===(int)$t->server_id&&$p->account_fingerprint===$t->account_fingerprint&&$p->registrar_key===$t->registrar_key;
        return $chosen&&in_array($action,['register','transfer'],true)&&!$p->{$action.'_enabled'}
            ?'Allow '.($action==='register'?'registrations':'transfers').' in More → Change registrar on .'.$t->tld.'.'
            :'Choose the registrar in More on .'.$t->tld.'.';
    }
}
