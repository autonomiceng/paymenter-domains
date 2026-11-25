<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Illuminate\Support\{Facades\Blade,HtmlString};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts,OfferState,SellerAvailability,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferTerms;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{AdmittedTransport,NameSiloLiveReservationPolicy,TestReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,TestCatalogue};
/** Registrar-choice wording lives here so the TLD page and dialog use the same causes. */
final class TldRegistrar
{
    private static function name(string $registrar): string {return ['enom'=>'Enom','namesilo'=>'NameSilo'][$registrar]??'registrar';}
    private static function missingPrice(string $registrar,string $ending): string
    {return 'No eligible '.self::name($registrar).' prices for .'.$ending.' are saved.';}
    private static function unreadablePrice(string $registrar,string $ending): string
    {return 'The saved '.self::name($registrar).' price for .'.$ending." can't be read.";}

    /** @return array<int,array{label:string,reason:?string,disabled:bool,price_issue:bool}> */
    public static function choices(int $id, ?bool $register=null, ?bool $transfer=null): array
    {
        $ending=Tld::findOrFail($id)->tld;$out=[];
        $policy=SalesPolicy::where('tld',$ending)->first();
        $register??=$policy?->register_enabled??false;
        $transfer??=$policy?->transfer_enabled??false;
        $rows=Tld::where('tld',$ending)->get()->keyBy('server_id');
        $servers=Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->get()->keyBy('id');
        $clock=app(Clock::class);
        foreach(Account::whereIn('server_id',$servers->keys())->orderBy('server_id')->get() as $account){
            $server=$servers[$account->server_id];
            $row=$rows[$account->server_id]??null;
            $prices=SellerAvailability::state($account,$row);
            $off=!$server->enabled||$server->extension!=='Domains';
            $facts=self::facts($account,$row,$clock);
            $unsupported=self::termsReason($account,$row,$facts,$clock->now(),$register,$transfer);
            $priceIssue=!$off&&$unsupported===null&&$prices!==SellerAvailability::READY;
            $reason=match(true){
                $off=>'This registrar account is off.',
                $unsupported!==null=>$unsupported,
                $prices===SellerAvailability::UNREADABLE=>self::unreadablePrice($account->registrar_key,$ending),
                $priceIssue=>self::missingPrice($account->registrar_key,$ending),
                !$account->processing_enabled=>'Orders are paused on this account.',
                default=>null,
            };
            $out[(int)$account->server_id]=['label'=>(string)$server->name,'reason'=>$reason,'disabled'=>$off||$unsupported!==null||$priceIssue,'price_issue'=>$priceIssue];
        }
        return $out;
    }

    public static function selectable(array $choices, ?int $except=null): bool
    {
        foreach($choices as $id=>$choice){
            if($id!==$except&&!$choice['disabled']){return true;}
        }
        return false;
    }

    public static function none(string $ending, bool $other=false): string
    {return 'No '.($other?'other ':'').'connected registrar can be selected for .'.$ending.' right now.';}

    /** Status comes from the account feed, even when the account has no local row for this TLD. */
    public static function priceRecovery(array $choices): array
    {
        $catalogue=StaffCatalogue::routed();
        $out=[];
        foreach($choices as $id=>$choice){
            if(!$choice['price_issue']){continue;}
            try{$status=$catalogue?->progress($id)??[];}catch(\Throwable){$status=[];}
            $state=$status['state']??null;
            $queued=$state==='queued'&&(isset($status['due_at'])||!empty($status['remaining']));
            $failure=$status['failure']??null;
            $applying=!empty($status['remaining'])&&isset($status['succeeded_at']);
            $completed=$state==='ready'&&isset($status['succeeded_at']);
            $reason=match(true){
                $failure!==null=>$choice['reason'].' Price sync failed. '.rtrim(TldFacts::syncCause($failure),'.').'.',
                $applying=>$choice['reason'].' Saving the latest prices.',
                $state==='running'=>$choice['reason'].' Price sync is running for this account.',
                $queued=>$choice['reason'].' Price sync queued.',
                $completed=>$choice['reason'].' Prices updated '.\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((int)$status['succeeded_at']).'.',
                default=>$choice['reason'],
            };
            $url=self::canRecheck($id,$status)?RegistrarFacts::accountUrl($id):null;
            $out[$id]=['label'=>$choice['label'],'reason'=>$reason,'url'=>$url];
        }
        return $out;
    }

    public static function canRecheck(int $id,?array $status=null): bool
    {
        // The account action also needs a bound TLD to enter the existing import writer.
        if(!SettingsActions::syncAll($id)->isVisible()){return false;}
        try{
            $status??=StaffCatalogue::routed()?->progress($id)??[];
            if(isset($status['failure'])||in_array($status['state']??null,['running','retrying'],true)
                ||($status['state']??null)==='queued'&&(isset($status['due_at'])||!empty($status['remaining']))){return false;}
            $binding=AccountBindings::binding(Account::where('server_id',$id)->sole());
            $first=Tld::where('server_id',$id)->orderBy('id')->first();
            if($first===null||$first->account_fingerprint!==$binding->fingerprint||$first->registrar_key!==$binding->registrarKey){return false;}
            if($binding->registrarKey==='enom'){return TestCatalogue::enabled($binding);}
            if($binding->registrarKey!=='namesilo'||!class_exists(AdmittedTransport::class)){return false;}
            $policy=$binding->environment===Environment::Test?new TestReservationPolicy:new NameSiloLiveReservationPolicy;
            $policy->requireFeature($binding,'catalogue');
            return true;
        }catch(\Throwable){return false;}
    }

    /** Explain exceptions outside the native select, so they stay visible when it opens. */
    public static function choiceSummary(array $choices, string $ending): HtmlString
    {
        $prices=self::priceRecovery($choices);
        $lines=self::selectable($choices)?[]:[e(self::none($ending))];
        foreach($choices as $id=>$choice){
            $price=$prices[$id]??null;
            $reason=$price['reason']??$choice['reason'];
            if($reason===null){continue;}
            $line='<strong>'.e($choice['label']).'</strong><br>'.e($reason);
            if(($url=$price['url']??null)!==null){
                $line.=' '.Blade::render('<x-filament::link :href="$url" size="sm">{{ $label }}</x-filament::link>',['url'=>$url,'label'=>'Open '.$choice['label']]);
            }
            $lines[]=$line;
        }
        return new HtmlString(implode('<br><br>',$lines));
    }

    private static function facts(Account $account,?Tld $row,Clock $clock): ?Facts
    {
        if($row===null||$row->account_fingerprint!==$account->account_fingerprint||$row->registrar_key!==$account->registrar_key){return null;}
        try{return OfferState::acquisitionFacts(AccountBindings::binding($account),$row,$clock);}catch(\Throwable){return null;}
    }
    /** Known terms must fit the proposed sale. The writer can record intent while independent terms are pending. */
    private static function termsReason(Account $account,?Tld $row,?Facts $facts,\DateTimeImmutable $now,bool $register,bool $transfer): ?string
    {
        if(!$row||!$facts){return null;}
        $ending=$row->tld;$registrar=(string)$account->registrar_key;
        $base=OfferTerms::termsBlock($facts,$registrar,$ending,false,1,false,$now);
        if($base==='unsupported_facts'){
            return 'This registrar '.OfferTerms::unsupportedCause($facts,$registrar,$ending).' for .'.$ending.'.';
        }
        if($base==='unsupported_term'){return 'This registrar does not offer annual renewals for .'.$ending.'.';}
        if(!$facts->fresh($now)){return null;}
        try{
            $intent=OfferState::decode($row->catalogue_intent);
            $defaults=OfferState::decode($account->catalogue_defaults);
        }catch(\Throwable){return "Saved sales settings can't be read for .".$ending.'.';}
        $longest=(int)($intent['longest']??$defaults['defaultLongest']??1);
        if(($register||!$transfer)&&OfferTerms::termsBlock($facts,$registrar,$ending,true,$longest,false,$now)!==null){
            return 'This registrar has no registration term within the saved limit for .'.$ending.'.';
        }
        if($transfer&&OfferTerms::termsBlock($facts,$registrar,$ending,false,$longest,true,$now)!==null){
            return 'This registrar does not offer transfers for .'.$ending.'.';
        }
        return null;
    }
    public static function refusal(\Throwable $error,array $data,?int $id): ?string
    {
        $ending=$id?Tld::whereKey($id)->value('tld'):null;
        if($ending===null){return 'This TLD changed. Reload the page and choose its registrar again.';}
        $account=Account::where('server_id',(int)($data['seller']??0))->first();
        $terms=null;
        if($error->getMessage()==='Explicit verified widening required.'&&$account!==null){
            $row=Tld::where('server_id',$account->server_id)->where('tld',$ending)->first();
            $clock=app(Clock::class);
            $terms=self::termsReason($account,$row,self::facts($account,$row,$clock),$clock->now(),
                (bool)($data['register']??false),(bool)($data['transfer']??false));
        }
        return match($error->getMessage()){
            'Seller offer missing.','Seller price missing.'=>self::missingPrice($account?->registrar_key??'',$ending),
            'Seller price unreadable.'=>self::unreadablePrice($account?->registrar_key??'',$ending),
            'Seller account missing.'=>'This registrar account was removed. Choose another registrar.',
            'Seller account off.'=>'This registrar account is off. Turn it on in Servers.',
            'Explicit verified widening required.'=>$terms===null?null:$terms.' Nothing was saved.',
            'Offer binding changed.','Price binding changed.','Seller offer group changed; retry.','Offer disappeared.'=>'Saved registrar prices changed. Reopen Change registrar and try again.',
            default=>null,
        };
    }
}
