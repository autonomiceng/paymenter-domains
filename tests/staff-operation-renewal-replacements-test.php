<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
// These assertions need native financial events, not PDF layout.
require __DIR__.'/Support/native-invoice-attachment.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements;
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
$preflight->cost='8.95';
// A temporarily unfunded virgin renewal can re-release only after exact funding restoration.
[$renew,$domain,$binding]=$makeRenewal(placeholderComFixtureName('staff-renewal-placeholder.com'));$renew->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->callAction('release')->assertNotified('Hold released');
same('queued',$renew->fresh()->state,'Held renewal re-release');$run($renew->fresh(),'hold');$run($renew->fresh(),'refund');
// Exact old credit settlement automatically releases an ordinary replacement before expiry.
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$automatic=$billing->invoice($domain->id,$user);
expect($automatic->id!==$renew->invoice_id,'Exact settled refund creates replacement without a staff step');
expect(RenewalReplacements::reviewed($renew->fresh(),$domain->fresh()),'Automatic exact-settlement proof retained');
same(null,$renew->fresh()->policy['renewal_replacement']['actor_id'],'Automatic recovery never invents a staff actor');
same([],RenewalReplacements::activity($domain->fresh()),'Unchanged refunded debt shows no false financial warning');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$invoice=$billing->invoice($domain->id,$user);
expect($invoice->id!==$renew->invoice_id,'Replacement creates separate invoice, keeps original');
$replacement=Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();
expect(str_contains($replacement->operation_key,':replacement:'),'Replacement key is distinct');same('awaiting_payment',$replacement->state,'Replacement is never sent or implicitly funded');
\App\Helpers\ExtensionHelper::addPayment($invoice,null,$replacement->amount);
$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');same('pending',$runRenewal($replacement,$binding)->state,'Replacement sealed normally');
InvoiceTransaction::create(['invoice_id'=>$renew->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
// The clock is on the expiry date, where owner requests stop; the scheduled path still reuses issued debt.
same($invoice->id,$billing->invoice($domain->id)->id,'Late old payment never duplicates admitted replacement');
expect(RenewalReplacements::activity($domain->fresh())!==[],'Late old financial activity is a read-only review fact');
$completion=app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class);$state->fail=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info;
$calls=count($state->calls);same('pending',$completion->run($replacement->id)->state,'Engine read remains available after late old payment');expect(count($state->calls)>$calls,'No global lock-time freeze');
$state->fail=null;$state->expiry='2028-10-18';$state->charge='8.00';
same([(int)$renew->id],RenewalReplacements::activityIds($domain->fresh()),'Late refunded-debt activity exposes the affected request ID');

// The late payment shows on the refunded request it landed on; the replacement still offers Check status.
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->assertSee('Payment received after credit. Check the invoice.');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$replacement->id])->assertDontSee('Payment received after credit')
    ->callAction('recheck')->assertNotified('Registrar checked');
same('succeeded',$replacement->fresh()->state,'Staff readback completes sealed replacement after old late payment');
// Before admission, changed old settlement still blocks a new obligation.
[$late,$lateDomain]=$makeRenewal(placeholderComFixtureName('staff-replacement-late-placeholder.com'));$run($late,'hold');$run($late->fresh(),'refund');$run($late->fresh(),'replacement');
InvoiceTransaction::create(['invoice_id'=>$late->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');commerceReject(fn()=>$billing->invoice($lateDomain->id,$user));
echo "Native staff-operation-renewal-replacements passed.\n";
$nativeSuccessful=true;
