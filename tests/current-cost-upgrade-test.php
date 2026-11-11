<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Cost,CurrentCost,Edit,FeedObserved,OfferState,Query};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CurrentCostSchema};
extract(commerceFixture());$t=$tlds[0];$binding=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
$q=new Query($binding,['com'],'register');$revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
// A damaged override prevents publication, but must not leave a second current cost behind.
$intent=OfferState::decode($t->catalogue_intent);$intent['pricing_rule']=['mode'=>'broken'];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
$clock->instant=$now->modify('+1 hour');
$cost=new Cost('renew',1,true,'9.1234',CostUnit::TermTotal,'synthetic',$clock->instant,$clock->instant->modify('+1 day'));
$before=DB::table('domains_prices')->where('server_id',$t->server_id)->where('action','renew')->sole();
$ports->apply(new FeedObserved($binding,'com',[$cost]),$revision());
$p=OfferState::query('domains_prices')->where('id',$before->id)->sole();
same('9.1234',OfferState::money($p->wholesale,4),'Feed updates the sole normalized cost even while publication is unavailable');
same(OfferState::money((string)$before->retail,2),OfferState::money($p->retail,2),'New observation does not replace published retail when its rule is invalid');
same($cost->total,CurrentCost::read($p)->total,'Reader uses the same current columns');
same('ready',\Paymenter\Extensions\Servers\Domains\Catalogue\SellerAvailability::state(Account::where('server_id',$t->server_id)->sole(),$t->fresh()),'Fractional costs remain readable to seller selection');
$foreign=(array)$p;unset($foreign['id']);$foreign['currency']='EUR';DB::table('domains_prices')->insert($foreign);
same('ready',\Paymenter\Extensions\Servers\Domains\Catalogue\SellerAvailability::state(Account::where('server_id',$t->server_id)->sole(),$t->fresh()),'An unrelated currency cannot make USD seller evidence unreadable');
// Rehearse retained legacy rows, with both matching and conflicting representations.
DB::table('migrations')->where('migration',CurrentCostSchema::MIGRATION)->delete();
DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json(OfferState::encodeCost($cost)),'cost_enabled'=>null,'cost_source_unit'=>null]);
CurrentCostSchema::install();same('9.1234',CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole())->total,'Matching old columns and JSON imported without changing cost');
$conflict=[...OfferState::encodeCost($cost),'total'=>'123.0000'];
DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json($conflict),'cost_enabled'=>null,'cost_source_unit'=>null]);
CurrentCostSchema::install();
same(null,CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole()),'Conflicting old costs stay unavailable');
$issues=OfferState::decode(DB::table('domains_tlds')->where('id',$t->id)->value('catalogue_issues'));
expect(isset($issues['current_cost_review'][$p->id]),'Both conflicting sources retained for a person');
$rows=DB::table('domains_prices')->orderBy('id')->get()->toJson();CurrentCostSchema::install();same($rows,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Interrupted backfill reruns without reclassifying rows');
// Repair from fresh provider evidence, then republish locally; holder retail remains distinct.
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$t->catalogue_intent]);
$clock->instant=$clock->instant->modify('+1 hour');$fresh=new Cost('renew',1,true,'10.0000',CostUnit::TermTotal,'synthetic',$clock->instant,$clock->instant->modify('+1 day'));
$ports->apply(new FeedObserved($binding,'com',[$fresh]),$revision());$ports->apply(Edit::republish($binding,'com'),$revision());
same('10.0000',CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole())->total,'Fresh observation replaces unknown cost and republishes');
echo "Single current cost, retained retail, checked backfill and repair passed.\n";$nativeSuccessful=true;
