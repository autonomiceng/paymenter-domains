<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,OfferState,StaffCatalogue};
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);staffSalesFixture();
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0]->fresh();
// Keep real catalogue snapshots while the synthetic fixture supplies local account readiness.
StaffCatalogue::routed();$routing=new ReflectionProperty(CallerRouting::class,'active');$active=$routing->getValue();
$active[StaffCatalogue::class]=new StaffCatalogue($ports,$clock);$routing->setValue(null,$active);
$facts=$t->catalogue_facts;$queue=DB::table('domains_observation_queues')->where('server_id',$binding->serverId);
$state=['failed_at'=>$now->getTimestamp()-120,'failure'=>'read_failed','due_at'=>$now->getTimestamp()+120];
$save=static function(array $state)use($queue):void{$queue->update(['offer_work'=>OfferState::json(['facts'=>['com'=>$state]])]);};
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);$save($state);
same(TldFacts::TERMS_FAILED,TldFacts::salesState($t->fresh())['reason'],'A current failed terms read is named: '.json_encode(TldFacts::salesState($t->fresh())));
Livewire::actingAs($actor)->test(ListTlds::class)->assertSee(TldFacts::TERMS_FAILED);
Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertSee('Sync prices tries again now.');
// A successful read clears failure but deliberately retains the historical failed_at.
$state['failure']=null;$state['succeeded_at']=$now->getTimestamp();$save($state);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>$facts]);
expect(TldFacts::salesState($t->fresh())['reason']!==TldFacts::TERMS_FAILED,'Recovered fresh terms clear failure');
$stale=OfferState::decode($facts);$stale['observedAt']=$now->modify('-2 days')->format(DATE_ATOM);$stale['freshUntil']=$now->modify('-1 day')->format(DATE_ATOM);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($stale)]);
same('Set to sell; checking registrar terms',TldFacts::salesState($t->fresh())['reason'],'Later stale terms do not revive a resolved failure');
Livewire::actingAs($actor)->test(ListTlds::class)->assertSee('Set to sell; checking registrar terms')->assertDontSee(TldFacts::TERMS_FAILED);
Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertDontSee('Sync prices tries again now.')->assertSee('Set to sell; checking registrar terms');
same(0,$reads->calls,'Staff guidance makes no provider calls');
$nativeSuccessful=true;echo "Recovered terms do not revive old failures.\n";
