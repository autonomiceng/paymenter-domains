<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Tests;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Cost,Intent,OfferState};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CostUnit};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};

/** Arrange known synthetic commerce rows for active-module tests, not an upgrade implementation.
 * Actual retained compatibility is tested with the pinned bridge database in retained-offer-package-test.
 */
final class OfferFixture
{
    public function batch(AccountBinding $binding): void
    {
        DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json(new Intent(defaultSell:false))]);
        Tld::where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)->orderBy('id')->chunkById(100,static function($rows)use($binding):void {
            foreach($rows as $tld){
                $prices=Price::where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)->where('tld',$tld->tld)->get();
                $retained=null;$terms=[];$transfers=false;
                foreach($prices as $price){
                    if($price->action==='register'){$terms[]=(int)$price->term;}
                    if($price->action==='transfer'){$transfers=true;}
                    if($price->action==='renew'&&(int)$price->term===1){$retained=$price->retail;}
                    if($price->wholesale===null||$price->fresh_until===null){continue;}
                    $cost=new Cost($price->action,(int)$price->term,true,OfferState::money($price->wholesale,4),CostUnit::TermTotal,$price->source,$price->observed_at->toDateTimeImmutable(),$price->fresh_until->toDateTimeImmutable());
                    DB::table('domains_prices')->where('id',$price->id)->update([...costColumns(OfferState::json(OfferState::encodeCost($cost)))]);
                }
                $intent=new Intent(defaultSell:false,sell:$terms!==[],longest:$terms?max($terms):null,transfers:$transfers,
                    stops:$tld->enabled?[]:['register','renew','transfer']);
                DB::table('domains_tlds')->where('id',$tld->id)->update(['catalogue_intent'=>OfferState::json($intent),
                    'catalogue_facts'=>null,'catalogue_revision'=>1,'catalogue_converted'=>true,'catalogue_retained_annual_retail'=>$retained]);
            }
        });
        DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$binding->serverId],['account_fingerprint'=>$binding->fingerprint,'offer_feed_run'=>1]);
    }
}
