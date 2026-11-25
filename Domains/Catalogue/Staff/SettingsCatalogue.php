<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState, Pricing, StaffCatalogue};

use Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CardFee,FreshInstallation,PricingRules,StaffSettingsReview,StaffSettingsWrites,TestCatalogue};

/** What the staff settings dialogs read from the installed catalogue, with the saved TLD row before activation. */
final class SettingsCatalogue
{
    /** The TLD's saved pricing rule, or the business default without a card fee. */
    public static function savedRule(int $server,?Tld $t): array
    { return $t?(StaffCatalogue::routed()?->pricing($server,$t)??(new PricingRules)->rule($t)):(new PricingRules)->defaultRule(fee:CardFee::NONE); }
    /** Which saved rule the TLD has, so an unreadable one can be named. */
    public static function ruleKind(Tld $t): string
    {
        $intent=OfferState::decode($t->catalogue_intent);
        return !Pricing::hasOwn($t)?'default':match($intent['pricing_rule']['mode']??$t->pricing_mode){'fixed'=>'fixed','markup_percent','markup_amount','markup_fee_percent'=>'markup',default=>'pricing'};
    }
    public static function usesDefault(?int $tld): bool
    {
        $catalogue=StaffCatalogue::routed();
        return $catalogue!==null?!$catalogue->own(Tld::findOrFail($tld)):(Tld::find($tld)?->use_default_rule??true);
    }
    public static function setting(?int $tld,string $key,string $column): mixed
    { return StaffCatalogue::routed()?->setting(Tld::findOrFail($tld),$key)??Tld::findOrFail($tld)->$column; }
    /** Sync needs an account whose price list we can read. */
    public static function syncReadable(?Account $account): bool
    {
        try{if(($catalogue=StaffCatalogue::routed())!==null){return $account!==null&&$catalogue->readable(AccountBindings::binding($account));}return $account!==null&&TestCatalogue::enabled(AccountBindings::binding($account));}
        catch(\Throwable){return false;}
    }
    public static function queueSync(int $actor,int $server,string $key): void
    {
        if(StaffCatalogue::routed()!==null){app(StaffSettingsWrites::class)->run(new StaffSettingsReview($actor,$server,null,'import',$key,''),[]);}
        else{SyncPrices::enqueue($actor,$server,$key);}
    }
    /** Each sold term's cost, current price and the price saving would publish. */
    public static function preview(Tld $t,array $rule): array
    { return (StaffCatalogue::routed() ?? throw new \DomainException(FreshInstallation::MESSAGE))->preview($t,$rule); }
    /** What the saved account terms allow for one TLD, with its current bounds; null when unreadable. */
    public static function allowedTerms(int $server,?int $tld): ?array
    {
        try{
            $account=Account::where('server_id',$server)->sole();$t=Tld::findOrFail($tld);
            $catalogue=StaffCatalogue::routed();
            if($catalogue!==null){$facts=$catalogue->page(collect([$t]))[$t->id]['facts'];$safe=$catalogue->restorable($t);$register=$safe['register']?$facts->terms['register']:[];
                $bounds=[...$register,$catalogue->setting($t,'min_years'),$catalogue->setting($t,'max_years')];
                return ['ending'=>$t->tld,'register'=>$register,'min'=>min($bounds),'max'=>max($bounds),'register_allowed'=>$safe['register'],'transfer_allowed'=>$safe['transfer']];}
            return null;
        }catch(\Throwable){return null;}
    }
}
