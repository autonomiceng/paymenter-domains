<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
/** Selection needs a real bound offer and an acquisition/annual-renewal price path.
 * Freshness, terms, account readiness and retail remain independent evaluator checks. No provider I/O. */
final class SellerAvailability
{
    public static function hasPrices(Account $account,?Tld $t): bool
    {
        if(!$t||!$t->catalogue_converted||$t->account_fingerprint!==$account->account_fingerprint||$t->registrar_key!==$account->registrar_key){return false;}
        $costs=[];
        foreach(DB::table('domains_prices')->where('server_id',$account->server_id)->where('tld',$t->tld)
            ->where('registrar_key',$account->registrar_key)->where('account_fingerprint',$account->account_fingerprint)->where('term',1)->get() as $price){
            if($price->catalogue_observation===null){continue;}
            $cost=OfferState::cost(OfferState::decode($price->catalogue_observation));
            if($cost->enabled){$costs[$cost->action]=true;}
        }
        return isset($costs['renew'])&&(isset($costs['register'])||isset($costs['transfer']));
    }
}
