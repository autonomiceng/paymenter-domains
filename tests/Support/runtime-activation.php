<?php
// Installed ZIP diagnostics and read-only activation acceptance. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Invoice,InvoiceItem,Credit};
use Illuminate\Support\Facades\{DB,Queue,Event};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Log\Events\MessageLogged;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationCandidates,RuntimeCandidates,RuntimeDiagnostics,RuntimeIneligible,RuntimeReason,RuntimeWork,RuntimePorts};
expect(!is_link(base_path('extensions/Servers/Domains')),'Activation tests use extracted ZIP bytes');
foreach([ActivationStatus::class,RuntimeCandidates::class,RuntimeDiagnostics::class] as $class){expect(str_starts_with((new ReflectionClass($class))->getFileName(),base_path('extensions/Servers/Domains/')),'Installed activation class');}
$clock=new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct(){$this->at=new DateTimeImmutable('2026-10-20T23:59:00Z');}
    public function now(): DateTimeImmutable {return $this->at;}
};
app()->instance(Clock::class,$clock);$worker=new RuntimeWork($clock,app(RuntimePorts::class));
DB::table('domains_psl_status')->where('id',1)->update(['last_attempt_at'=>$clock->now()->getTimestamp()]); // No incidental network admission.
$logs=[];Event::listen(MessageLogged::class,static function($event)use(&$logs):void {$logs[]=['level'=>$event->level,'message'=>$event->message,'context'=>$event->context];});
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','state'=>'awaiting_code','funding_basis'=>'offline_demo']);
$holding->update(['status'=>'transfer_pending','provider_expires_at'=>'2027-10-20']);
$bare=Operation::create(['domain_id'=>$holding->id,'service_id'=>9001,'server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'authority_generation'=>$holding->authority_generation,'operation_key'=>'no-native-invoice','action'=>'transfer','term'=>1,'state'=>'awaiting_code','currency'=>'USD','amount'=>'20.00']);
same(0,RuntimeCandidates::transfer(Operation::query())->count(),'Display transfers excluded before guards');
same(0,RuntimeCandidates::billing(Domain::query())->count(),'Display holdings excluded before billing');
$before=[Invoice::count(),InvoiceItem::count(),Credit::sum('amount'),Operation::orderBy('id')->get()->map->getRawOriginal()->all()];
$worker->tick();$worker->service(9001);$clock->at=$clock->at->modify('+1 minute');$worker->tick();
same($before,[Invoice::count(),InvoiceItem::count(),Credit::sum('amount'),Operation::orderBy('id')->get()->map->getRawOriginal()->all()],'Display fixtures cannot invoice, credit, or mutate attempts');
same([],array_values(array_filter($logs,fn($e)=>$e['level']==='warning'&&$e['message']==='Domains runtime work failed.')),'Display rows produce no runtime producer warnings');
expect(!in_array($op->id,array_merge(...array_values(ObservationCandidates::next('timeout'))),true),'Offline transfer never reaches timeout guards');
// Genuine native invoice/item, with deliberately lost service authority: this must remain actionable.
$invoice=Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending','due_at'=>'2026-11-01','send_create_email'=>false]);
$item=$invoice->items()->create(['quantity'=>1,'price'=>'20.00','description'=>'Synthetic acquisition']);
DB::table('domains_operations')->where('id',$bare->id)->update(['invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id]);
expect(in_array($bare->id,array_merge(...array_values(ObservationCandidates::next('timeout'))),true),'Native invoice-backed transfer remains a timeout candidate');
expect(RuntimeCandidates::billing(Domain::whereKey($holding->id))->exists(),'Current-generation native invoice authority remains a billing candidate');
DB::table('domains_domains')->where('id',$holding->id)->update(['authority_generation'=>$holding->authority_generation+1]);
expect(!RuntimeCandidates::billing(Domain::whereKey($holding->id))->exists(),'Previous-generation native invoice authority cannot admit billing');
// Advisory selection retains adopted holdings; the unchanged locked guards validate adoption evidence.
DB::table('domains_domains')->where('id',$holding->id)->update(['adoption_id'=>1]);
expect(RuntimeCandidates::billing(Domain::whereKey($holding->id))->exists(),'Retained adoption marker does not require acquisition invoice funding');
DB::table('domains_domains')->where('id',$holding->id)->update(['adoption_id'=>null,'authority_generation'=>$holding->authority_generation]);
$logs=[];$worker->service(9001);
$warnings=array_values(array_filter($logs,fn($e)=>$e['level']==='warning'));
same(1,count($warnings),'Broken native authority retains one actionable warning');
same(['producer'=>'transfer_payment','reason'=>'guard_refused','row_id'=>(int)$bare->id],$warnings[0]['context'],'Warning has only bounded code and numeric locator');
// Sent work remains in the generic operation scan even when invoice authority is unavailable now.
DB::table('domains_operations')->where('id',$bare->id)->update(['state'=>'uncertain','invoice_item_id'=>null]);
Queue::fake();$clock->at=$clock->at->modify('+16 minutes');$worker->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$bare->id);
DB::table('domains_operations')->where('id',$bare->id)->update(['state'=>'rejected']);
expect(in_array($bare->id,array_merge(...array_values(ObservationCandidates::next('refund'))),true),'Refund recovery does not use the new invoice authority filter');
// Shared atomic daily slots use the UTC date, independently of producer and reason.
Illuminate\Support\Facades\Schema::create('cache',function($t):void {$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();});
config(['cache.default'=>'database']);$diagnostics=new RuntimeDiagnostics($clock);$logs=[];
foreach(range(1,3) as $_){$diagnostics->ineligible('billing',RuntimeReason::BillingOff);}
same(1,count($logs),'Same producer/reason logs at most once per UTC day');
(new RuntimeDiagnostics($clock))->ineligible('billing',RuntimeReason::BillingOff);same(1,count($logs),'A separate diagnostics instance shares the daily slot');
$diagnostics->ineligible('timeout',RuntimeReason::BillingOff);$diagnostics->ineligible('billing',RuntimeReason::ProcessingOff);same(3,count($logs),'Distinct producer/reason slots');
$clock->at=$clock->at->modify('+1 day');$diagnostics->ineligible('billing',RuntimeReason::BillingOff);same(4,count($logs),'Next UTC day has a new slot');
$diagnostics->attempt('billing',fn()=>throw new RuntimeIneligible(RuntimeReason::MissingAnchor),$holding->id);
same('info',$logs[4]['level'],'Typed missing anchor is expected');
$diagnostics->attempt('billing',fn()=>throw new RuntimeException('PRIVATE-EPP PRIVATE-CUSTOMER PRIVATE-CREDENTIAL'),$holding->id);
same('warning',$logs[5]['level'],'Unexpected failures remain warnings');expect(!str_contains(json_encode($logs),'PRIVATE-'),'No raw exception or customer data logged');
$logs=[];
foreach([
    [new Paymenter\Extensions\Servers\Domains\Services\WriteHeld('write_acceptance_required'),'write_acceptance_required'],
    [new Paymenter\Extensions\Servers\Domains\Services\RenewalRefused(Paymenter\Extensions\Servers\Domains\Data\RenewalCode::Authority),'renewal_authority_invalid'],
    [new PDOException('PRIVATE-SQL'),'database_failure'],[new InvalidArgumentException('PRIVATE-INPUT'),'invalid_input'],
    [new LogicException('PRIVATE-INVARIANT'),'invariant_failed'],[new DomainException('PRIVATE-GUARD'),'guard_refused'],
    [new Illuminate\Database\Eloquent\ModelNotFoundException,'native_record_missing'],
    [new class('PRIVATE-ANONYMOUS') extends RuntimeException {},'producer_failed'],
] as [$error,$reason]){
    $diagnostics->attempt('billing',fn()=>throw $error,$holding->id);
    same(['producer'=>'billing','reason'=>$reason,'row_id'=>(int)$holding->id],end($logs)['context'],'Actionable classification is specific and contains no class path or exception text');
}
expect(!str_contains(json_encode($logs),'PRIVATE-'),'All actionable exception categories redact messages');
config(['cache.default'=>'array']);$logs=[];$diagnostics->ineligible('billing',RuntimeReason::BillingOff);same([],$logs,'Local cache cannot promise a cross-process daily limit');
// Native panel discovery, permission revocation and secret-safe closed gates.
require_once __DIR__.'/installed.php';installedPanel(); // Discover after the first native server exists.
$panel=Filament\Facades\Filament::getPanel('admin');Filament\Facades\Filament::setCurrentPanel($panel);
expect(in_array(RegistrarAccount::class,$panel->getPages(),true),'Native discovery finds the registrar account page');
// Activation status's go-live facts now live in the account's Setup checklist; its old address redirects.
Livewire\Livewire::actingAs($staff)->test(ActivationStatus::class)->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl());
$account=['account'=>$server->id];
$test=Livewire\Livewire::actingAs($staff)->test(RegistrarAccount::class,$account)->assertSuccessful();
expect(!str_contains($test->html(),'No TLDs chosen yet'),'Accepted catalogue is not falsely reported missing');
DB::table('settings')->where('settingable_id',$server->id)->where('key',Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::KEY)->update(['value'=>'invalid-synthetic-acceptance']);
$test=Livewire\Livewire::actingAs($staff)->test(RegistrarAccount::class,$account)->assertSuccessful();$html=$test->html();
foreach(['Setup checklist','Supported TLDs','No TLDs chosen yet','Registrations','Transfers','Renewal invoices','Turn on','System checks','Registrar runtime'] as $word){expect(str_contains($html,$word),'Account page shows go-live fact '.$word);}
foreach(['synthetic-held-account','synthetic-password','PRIVATE-','queued-held-placeholder.com','held-package-operation','domains_credentials','account_fingerprint'] as $secret){expect(!str_contains($html,$secret),'Account page excludes secrets and ledger payloads');}
$plain=staffUser([]);Livewire\Livewire::actingAs($plain)->test(RegistrarAccount::class,$account)->assertForbidden();
$staff->role->update(['permissions'=>[]]);Livewire\Livewire::actingAs($staff)->test(RegistrarAccount::class,$account)->assertForbidden();
$staff->role->update(['permissions'=>[Staff::VIEW]]);
echo "Installed activation: display isolation, retained recovery, bounded diagnostics and permission-gated local status passed.\n";
