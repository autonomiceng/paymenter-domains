<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native install-only retail recovery.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Retail};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,NativeServerGuard};
extract(commerceFixture(true));
foreach($tlds as$t){$p=OfferState::decode($t->fresh()->catalogue_published_revision);unset($p['retailRule']);DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_published_revision'=>OfferState::json($p)]);}
$unpublished=$tlds[1]->fresh()->replicate();$unpublished->tld='unpublished';$unpublished->catalogue_published_revision=null;$intent=OfferState::decode($unpublished->catalogue_intent);$intent['pricing_rule']=['mode'=>'unreadable'];$unpublished->catalogue_intent=OfferState::json($intent);$unpublished->save();
$nullBefore=$unpublished->fresh()->getRawOriginal();$price=$native->product($tlds[0],'register')->plans()->where('billing_period',1)->sole()->prices()->sole();
$rows=static fn($id)=>[DB::table('domains_tlds')->where('id',$id)->first(),DB::table('domains_prices')->where('server_id',$tlds[0]->server_id)->orderBy('id')->get()->toJson(),DB::table('prices')->where('id',$price->id)->first()];
DB::table('prices')->where('id',$price->id)->update(['price'=>'0.01']);
$before=serialize($rows($tlds[0]->id));$logs=0;
Log::listen(static function($event)use(&$logs):void{if($event->message==='Domains retail upgrade deferred.'){$logs++;throw new RuntimeException('Synthetic logging failure');}});
DB::statement('CREATE TRIGGER isolate_retail BEFORE UPDATE ON prices WHEN OLD.id = '.$price->id." BEGIN SELECT RAISE(ABORT, 'synthetic isolated TLD failure'); END");
(new Installation)->install();
same(Retail::VERSION,OfferState::decode($tlds[1]->fresh()->catalogue_published_revision)['retailRule']??null,'Healthy sibling upgrades despite earlier native failure and failing logger');
same($before,serialize($rows($tlds[0]->id)),'Failed TLD rolls back publication, costs and native amount atomically');
expect($logs>0,'Deferred TLD receives a bounded diagnostic');same($nullBefore,$unpublished->fresh()->getRawOriginal(),'Unpublished row does not churn revision or cache publication');
$healthy=$tlds[1]->fresh()->getRawOriginal();(new NativeServerGuard)->boot();
App\Models\Server::create(['name'=>'New Domains account','type'=>'server','extension'=>'Domains','enabled'=>true]);
same($healthy,$tlds[1]->fresh()->getRawOriginal(),'Current sibling stays unchanged during new-server install');
same($nullBefore,$unpublished->fresh()->getRawOriginal(),'Repeated install leaves unpublished own rule untouched');
DB::statement('DROP TRIGGER isolate_retail');(new Installation)->install();
same(Retail::VERSION,OfferState::decode($tlds[0]->fresh()->catalogue_published_revision)['retailRule']??null,'Ordinary guarded retry recovers the deferred TLD');
echo "Native install isolates failed retail publications and preserves unopened rows\n";$nativeSuccessful=true;
