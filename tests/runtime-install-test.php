<?php
// Native lifecycle acceptance with synthetic registrars. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Server,Extension};
use Illuminate\Support\Facades\{DB,Event,Schema};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,Runtime,RuntimePorts,RuntimeSchema,ReminderSchedules,AccountBindings,NativePresenter,StaffSettingsReview as Review,StaffSettingsWrites,NativeCatalogue,NativeServerGuard};
use Paymenter\Extensions\Servers\Domains\Models\Account;
// Start through the authentic current fresh initializer before exercising retained lifecycle.
$entry=new Domains([]);$entry->installed(); // The native entry performs fresh initialization and verifies every schema.
$receipts=\Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema::TABLE;
DB::table($receipts)->insert(['delivery_key'=>str_repeat('a',64),'user_id'=>1,'notification_id'=>2]);
// Each retained receipt refusal uses its own restored fixture.
$receiptSchema=\Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema::class;
$receiptRecord=DB::table('migrations')->where('migration',Installation::BASELINE)->sole();
DB::table('migrations')->where('id',$receiptRecord->id)->delete();
$receiptRefused=false;
try{$entry->installed();}catch(DomainException $e){$receiptRefused=true;}
expect($receiptRefused,'Receipt storage without migration history refuses installation');
DB::table('migrations')->insert((array)$receiptRecord);
Schema::table($receipts,fn($b)=>$b->dropColumn('notification_id'));
$receiptRefused=false;
try{$entry->installed();}catch(RuntimeException $e){same('Retained Domains schema mismatch: '.$receipts,$e->getMessage(),'Receipt schema refusal is specific');$receiptRefused=true;}
expect($receiptRefused,'Recorded receipt storage with a missing column refuses installation');
Schema::drop($receipts);$receiptSchema::install();
DB::table($receipts)->insert(['delivery_key'=>str_repeat('a',64),'user_id'=>1,'notification_id'=>2]);
$entry->upgraded('0.0.1');$entry->uninstalled();$entry->installed();
$receiptMigration=require dirname(__DIR__).'/Domains/database/migrations/2026_11_23_000001_create_domains_schema.php';$receiptMigration->down();
same(2,(int)DB::table($receipts)->sole()->notification_id,'Install, upgrade, uninstall and rollback retain recipient receipts');
Runtime::register();same(app(RuntimePorts::class),app(RuntimePorts::class),'One port authority');expect(!app()->bound(\Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck::class),'Disabled TEST transfer preserves absent cart precheck');
$entry->boot();$entry->boot();
same(1,count(Event::getListeners('navigation')),'One public navigation authority');
same(1,count(Event::getListeners('navigation.dashboard')),'One dashboard navigation authority');
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';
app('router')->getRoutes()->refreshNameLookups();
$navigation=Event::dispatch('navigation')[0];
$transfer=collect($navigation['children'])->firstWhere('name','Transfer a domain');
same(route('domains.domains.transfer'),$transfer['url']??null,'Transfer entry remains discoverable while TEST transfers are off');
same(route('domains.domains.index'),Event::dispatch('navigation.dashboard')[0]['url'],'Domains sidebar reaches the full owned list');
$events=app(Illuminate\Console\Scheduling\Schedule::class)->events();
same(1,count(array_filter($events,fn($e)=>$e->description==='domains-runtime')),'One scheduler authority');
$ports=new RuntimePorts;$ports->initialManagedRecordObservations();$calls=0;
$ports->registerInitialManagedRecordObservations(function()use(&$calls){$calls++;});$ports->initialManagedRecordObservations();same(1,$calls,'Initial managed-record seam runs only registered producer');
commerceReject(fn()=>$ports->registerInitialManagedRecordObservations(fn()=>null));
commerceReject(fn()=>app(RuntimePorts::class)->registerInitialManagedRecordObservations(fn()=>null));
// Library fixture supplies verified synthetic identities without any transport or account fallback.
$installedRuntime=app(Runtime::class);$installedPorts=app(RuntimePorts::class);$f=commerceFixture(true,static fn()=>null);extract($f);app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);
$t=$tlds[0];app(NativeServerGuard::class)->pause($t->server_id);
same(false,Account::where('server_id',$t->server_id)->sole()->processing_enabled,'Pause persists');
ReminderSchedules::enroll($t->server_id);$row=DB::table('domains_reminder_schedules')->where('server_id',$t->server_id)->sole();same(true,(bool)$row->enabled,'New connections default reminder schedules On');
$u=staffUser(array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
$review=fn()=>new Review($u->id,$t->server_id,null,'processing',Review::ticket($u->id,$t->server_id,null,'processing',Review::stamp($t->server_id,null)),'Review processing configuration');
$writer=new StaffSettingsWrites($native,$sellers,$bindings);
commerceReject(fn()=>$writer->run($review(),['enabled'=>true,'confirmed'=>false]));
commerceReject(fn()=>$writer->run($review(),['enabled'=>true,'confirmed'=>true]));
same(false,Account::where('server_id',$t->server_id)->sole()->processing_enabled,'No acceptance or shared workers means no activation');
$bindings->setProcessing($t->server_id,true,static fn()=>null); // Synthetic existing activation; exercise a real On→Off transition.
$writer->run($review(),['enabled'=>false,'confirmed'=>true]);
$history=Review::historyRows(Server::findOrFail($t->server_id));$audit=end($history);
same('Pause or resume orders',$audit['action'],'Processing change audited');
same($u->id,$audit['actor_id'],'Actual processing actor is retained');
same([true,false],[$audit['before']['processing'],$audit['after']['processing']],'Audit records the real On to Off transition');
app()->instance(Runtime::class,$installedRuntime);app()->instance(RuntimePorts::class,$installedPorts);
// Generic native lifecycle changes cannot repurpose or remove retained identities.
commerceReject(fn()=>Server::findOrFail($t->server_id)->update(['extension'=>'Enom']));
commerceReject(fn()=>Server::findOrFail($t->server_id)->delete());
commerceReject(fn()=>Server::findOrFail($t->server_id)->update(['type'=>'other']));
$changed=Server::findOrFail($t->server_id);$changed->extension='Enom';commerceReject(fn()=>$changed->delete());
$legacy=Server::create(['type'=>'server','extension'=>'Enom','name'=>'Legacy','enabled'=>false]);
$setting=$legacy->settings()->create(['key'=>'operations_enabled','value'=>false,'type'=>'boolean']);
DB::table('settings')->where('id',$setting->id)->update(['value'=>'1']);
try{$entry->installed();expect(false,'Legacy active writer refused');}catch(DomainException $e){expect(str_contains($e->getMessage(),'legacy Enom'),'Clear legacy pause message');}
$legacy->settings()->where('key','operations_enabled')->update(['value'=>'0']);
// Native Migrator skipping an already recorded migration must still verify retained shape.
Schema::table('domains_runtime_turns',fn($b)=>$b->dropColumn('finished_at'));
try{$entry->installed();expect(false,'Retained mismatch refused');}catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Retained schema checked even when migration recorded');}
echo "Native install/upgrade/retention, duplicate boot, initial-observation seam, activation and legacy refusal passed.\n";$nativeSuccessful=true;
