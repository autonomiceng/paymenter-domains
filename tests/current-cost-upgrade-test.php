<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\{DB,Schema};
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
$retiredMirror=!Schema::hasColumn('domains_prices','catalogue_observation');
if($retiredMirror){
    $retirement=DB::table('migrations')->where('migration','2026_11_11_000066_retire_catalogue_mirrors')->sole();
    DB::table('migrations')->where('id',$retirement->id)->delete();
    // SQLite appends columns; rebuild the old tail in order for the retained-schema verifier.
    $tail=DB::table('domains_prices')->get(['id','catalogue_created','cost_enabled','cost_source_unit']);
    Schema::table('domains_prices',static fn($t)=>$t->dropColumn(['catalogue_created','cost_enabled','cost_source_unit']));
    Schema::table('domains_prices',static function($t):void {
        $t->json('catalogue_observation')->nullable();$t->boolean('catalogue_created')->default(false);
        $t->boolean('cost_enabled')->nullable();$t->string('cost_source_unit',32)->nullable();
    });
    foreach($tail as $row){$values=(array)$row;unset($values['id']);DB::table('domains_prices')->where('id',$row->id)->update($values);}
}
DB::table('migrations')->where('migration',CurrentCostSchema::MIGRATION)->delete();
DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json(OfferState::encodeCost($cost)),'cost_enabled'=>null,'cost_source_unit'=>null]);
CurrentCostSchema::install();same('9.1234',CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole())->total,'Matching old columns and JSON imported without changing cost');
$conflict=[...OfferState::encodeCost($cost),'total'=>'123.0000'];
DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json($conflict),'cost_enabled'=>null,'cost_source_unit'=>null]);
CurrentCostSchema::install();
same(null,CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole()),'Conflicting old costs stay unavailable');
$issues=OfferState::decode(DB::table('domains_tlds')->where('id',$t->id)->value('catalogue_issues'));
expect(isset($issues['current_cost_review'][$p->id]),'Both conflicting sources retained for a person');
// A disabled observation cannot consume a retained amount that exceeds the current precision.
$disabled=OfferState::json([...OfferState::encodeCost($cost),'enabled'=>false,'total'=>null]);
DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>$disabled,'wholesale'=>'12.34567800','cost_enabled'=>null,'cost_source_unit'=>null]);
$retained=OfferState::query('domains_prices')->where('id',$p->id)->sole()->wholesale;
CurrentCostSchema::install();
$reviewed=OfferState::query('domains_prices')->where('id',$p->id)->sole();
same($retained,$reviewed->wholesale,'Upgrade preserves a retained amount beside a disabled observation');
same(null,CurrentCost::read($reviewed),'Conflicting disabled observation stays unavailable');
$issues=OfferState::decode(DB::table('domains_tlds')->where('id',$t->id)->value('catalogue_issues'));
same([$disabled,$retained],[$issues['current_cost_review'][$p->id]['observation'],$issues['current_cost_review'][$p->id]['columns']['wholesale']],'Review retains both the disabled observation and the original amount');
$rows=DB::table('domains_prices')->orderBy('id')->get()->toJson();CurrentCostSchema::install();same($rows,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Interrupted backfill reruns without reclassifying rows');
if($retiredMirror){
    Schema::table('domains_prices',static fn($t)=>$t->dropColumn('catalogue_observation'));
    DB::table('migrations')->insert((array)$retirement);
}
// Repair from fresh provider evidence, then republish locally; holder retail remains distinct.
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$t->catalogue_intent]);
$clock->instant=$clock->instant->modify('+1 hour');$fresh=new Cost('renew',1,true,'10.0000',CostUnit::TermTotal,'synthetic',$clock->instant,$clock->instant->modify('+1 day'));
$ports->apply(new FeedObserved($binding,'com',[$fresh]),$revision());$ports->apply(Edit::republish($binding,'com'),$revision());
same('10.0000',CurrentCost::read(OfferState::query('domains_prices')->where('id',$p->id)->sole())->total,'Fresh observation replaces unknown cost and republishes');
echo "Single current cost, retained retail, checked backfill and repair passed.\n";$nativeSuccessful=true;
