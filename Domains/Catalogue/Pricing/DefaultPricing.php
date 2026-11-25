<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Pricing;

use App\Models\Server;
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CatalogueActivation, Edit, OfferState, Pricing, StaffCommands, Writer};
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\CurrentCost;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, BusinessSetting, CardFee, PricingRule, PricingRules, StaffSettingsReview, StorefrontCatalogueCache};

/** One global review saves once; each account then publishes independently, matching the legacy F51 result. */
final class DefaultPricing
{
    public function __construct(private readonly Writer $writer, private readonly Clock $clock, private readonly ?\Closure $current) {}

    public function publish(StaffSettingsReview $review, array $rule, array $fee): array
    {
        if(DB::transactionLevel()!==0||$review->action!=='default_pricing'||$review->serverId!==StaffSettingsReview::ALL_ACCOUNTS||$review->tldId!==null){throw new \DomainException('Business-wide pricing review required.');}
        $rule=PricingRule::validate($rule);
        if($rule['mode']==='fixed'){throw new \InvalidArgumentException('Set an own price on each TLD instead.');}
        $targets=DB::transaction(fn():array=>$this->save($review,$rule,$fee));
        $summary=['published'=>0,'failed'=>[],'skipped'=>[]];
        foreach(Account::orderBy('server_id')->get() as $a){
            $server=Server::find($a->server_id);$name=$server?->name??'Server #'.$a->server_id;
            if(!$server?->enabled){$summary['skipped'][]=$name;continue;}
            try{foreach($targets[$a->server_id]??[] as $target){$this->republish($a,$target,$name,$summary);}}
            catch(\Throwable $error){
                $summary['failed'][$name.' #'.$a->server_id]??=[];
                Log::warning('Domains default pricing account failed.',['server_id'=>(int)$a->server_id,'exception_class'=>$error::class]);
            }
        }
        try{$review->finish('publication_completed',$summary);}
        catch(\Throwable $error){$summary['audit_failed']=true;Log::warning('Domains pricing publication outcome audit failed.',['action'=>$review->action,'exception_class'=>$error::class]);}
        return $summary;
    }

    /** Save the rule and fee once, then mark each offer the change affects. */
    private function save(StaffSettingsReview $review, array $rule, array $fee): array
    {
        $review->lockActor();$review->lockAccounts();
        if($this->current){($this->current)();}else{CatalogueActivation::active(CatalogueActivation::generation());}
        $review->check(false,static fn()=>StaffCommands::stamp(StaffSettingsReview::ALL_ACCOUNTS));
        // A fee-only edit cannot change an ordinary default's retail calculation or publication authority.
        try{$prior=(new PricingRules)->defaultRule(true,CardFee::NONE);}
        catch(\DomainException|\InvalidArgumentException){$prior=null;}
        $ruleChanged=$prior!==PricingRule::validate([...$rule,'card_fee'=>CardFee::NONE]);
        try{$priorFee=CardFee::read(true);}
        catch(\DomainException|\InvalidArgumentException){$priorFee=null;}
        $feeChanged=$priorFee!==$fee;
        BusinessSetting::saveLocked(PricingRules::KEY,$rule);
        BusinessSetting::saveLocked(CardFee::KEY,$fee);
        $targets=[];$accounts=Account::orderBy('server_id')->lockForUpdate()->get()->keyBy('server_id');
        foreach(Tld::orderBy('id')->lockForUpdate()->get() as $t){
            $a=$accounts[$t->server_id]??null;
            if($a===null||$t->account_fingerprint!==$a->account_fingerprint||$t->registrar_key!==$a->registrar_key){continue;}
            // Read the saved rule independently of the new fee; unreadable rules keep their TLD warning.
            try{$own=Pricing::own($t,CardFee::NONE);
                if($own!==null){PricingRule::validate([...$own,'card_fee'=>CardFee::NONE]);}}
            catch(\DomainException|\InvalidArgumentException){continue;}
            if($own!==null&&$own['mode']!=='markup_fee_percent'){continue;}
            $feeMode=($own??$rule)['mode']==='markup_fee_percent';
            if(!self::pending($t,$a)&&!($own===null&&$ruleChanged)&&!($feeMode&&$feeChanged)){continue;}
            if($own!==null){PricingRule::validate([...$own,'card_fee'=>$fee]);}
            $targets[$t->server_id][]=['id'=>(int)$t->id,'own'=>$own];
            // Failed publications must not leave old prices purchasable after the shared rule changes.
            $t->increment('catalogue_revision');
        }
        StorefrontCatalogueCache::invalidateAfterCommit();$review->record();return $targets;
    }

    /** Discovery invalidates the offer revision before publication. A failed turn remains pending. */
    private static function pending(Tld $t, Account $a): bool
    {
        try{
            $published=OfferState::decode($t->catalogue_published_revision);
            $revision=OfferState::revision($t,OfferState::decode($a->catalogue_defaults),DB::table('domains_sales_policies')->where('tld',$t->tld)->first());
            return ($published['offer']??null)!==$revision->offer||($published['defaults']??null)!==$revision->defaults||($published['selection']??null)!==$revision->selection;
        }
        catch(\JsonException|\TypeError){return true;}
    }

    /** Publish one target under current costs; a rule switched since discovery is no longer this save's target. */
    private function republish(Account $a, array $target, string $name, array &$summary): void
    {
        $id=$target['id'];$t=Tld::findOrFail($id);$pricingChanged=false;
        try{
            $rows=OfferState::query('domains_prices')->where('server_id',$a->server_id)->where('tld',$t->tld)->where('account_fingerprint',$a->account_fingerprint)->where('currency','USD')->get();
            if($rows->isEmpty()){throw new \DomainException('Current costs required.');}
            $freshness=CatalogueFreshness::forAccount((int)$a->server_id);
            foreach($rows as $p){if($p->cost_enabled===null||!$freshness->cost(CurrentCost::read($p))->fresh($this->clock->now())){throw new \DomainException('Current costs required.');}}
            $b=AccountBindings::binding($a);
            // Target discovery precedes this turn. Recheck after acquiring its writer mutexes.
            $this->writer->withGuard(static function()use($id,$target,&$pricingChanged):void {
                if(Pricing::own(Tld::findOrFail($id),CardFee::NONE)!==$target['own']){
                    $pricingChanged=true;throw new \DomainException('TLD pricing changed during publication.');
                }
            })->apply(Edit::republish($b,$t->tld),OfferState::revision($t,OfferState::decode($a->catalogue_defaults),DB::table('domains_sales_policies')->where('tld',$t->tld)->first()));
            $summary['published']++;
        }catch(\Throwable $error){
            // Cost preflight can fail before the locked check.
            if(!$pricingChanged){
                try{$pricingChanged=Pricing::own(Tld::findOrFail($id),CardFee::NONE)!==$target['own'];}
                catch(\Throwable){/* Preserve the original publication failure when current pricing cannot be read. */}
            }
            if($pricingChanged){return;}
            $summary['failed'][$name.' #'.$a->server_id][]='.'.$t->tld;
            if(!$error instanceof \DomainException){Log::warning('Domains default pricing publish failed.',['tld'=>$t->tld,'exception_class'=>$error::class]);}
        }
    }
}
