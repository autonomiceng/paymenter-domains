<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native observer and both family views over synthetic transports.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation};
// One accepted Enom holding and real Blade are needed to catch accidental provider dispatch, not a protocol stub.
$fixtureRegistrars=['enom'];
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=static fn()=>initializedRenewalFixture();
require __DIR__.'/Support/read-retry.php';
set_exception_handler(static function(Throwable $error):never {fwrite(STDERR,$error::class.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
require_once __DIR__.'/Support/staff-panel.php';
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.recheck']);
// Locally seeded examples on a real registrar account must never be mistaken for remote registrations.
config(['domains.local_demo_markers'=>['synthetic_example']]);
Illuminate\Support\Facades\DB::table('domains_observation_work')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'domain_id'=>$d->id,'generation'=>$d->authority_generation,'due_at'=>0,'tries'=>5,'resumes'=>1,'safe_code'=>'read_failed']);
$d->refresh()->update(['snapshot'=>[...$d->snapshot,'synthetic_example'=>'contacts']]);
$before=count($state->calls);$core=count($observation->calls);
same([],$management->available($user,$d->fresh(),$driver,['lock']),'Local examples grant no management controls');
commerceReject(fn()=>$submit($d->fresh(),$driver,'lock',['locked'=>false]));
same('local_demo',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'Local examples resolve without a registrar read');
same([$before,$core],[count($state->calls),count($observation->calls)],'Local examples make no provider calls');
expect(Paymenter\Extensions\Servers\Domains\Support\DemoData::is($d->fresh()),'Local examples are tagged Demo');
foreach($familyViews() as $surface=>$html){expect(str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'),'Local demo data has no registrar records. No sync is needed.'),$surface.' explains every Missing family once');}
expect(!Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::stopped($d->fresh()),'A local example needs no manual read recovery');
staffPanel();
Illuminate\Support\Facades\Auth::login($staff);
$notice=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh());
same('Local demo data. This domain was not registered. No sync is needed.',$notice['title'],'The domain explains why its records are missing');
expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::syncNow($d->fresh()),'Local examples offer no pointless Sync now');
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee('Local demo data')->assertSee('Missing')->assertDontSee('Use Sync now')->assertDontSee('Resume sync')->assertActionHidden('sync_now');
Illuminate\Support\Facades\DB::table('domains_domains')->where('id',$d->id)->update(['snapshot'=>'unreadable synthetic snapshot']);
same([],$management->available($user,$d->fresh(),$driver,['lock']),'Unreadable markers grant no controls without breaking the page');
$nativeSuccessful=true;echo "Local demo records stay local.\n";
