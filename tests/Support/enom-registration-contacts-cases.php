<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestCalls, EnomTestCallLimit, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, NativeCatalogue, ObservationWork, Runtime, RuntimePorts, RuntimeWork, StaffSettingsReview as Review, StaffSettingsWrites, Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require __DIR__.'/enom-test-sales-fixture.php';
$reader=$bindings->reader($server->id,true);$generalReader=$bindings->reader($server->id);
$unpaidOp=Domain::where('domain','unpaid-test-placeholder.com')->sole()->operations()->sole();
$op=Domain::where('domain','paid-test-placeholder.com')->first()?->operations()->sole();
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static fn()=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed')));
// Missing frozen contacts must reach the existing contact-specific hold and correction path before any Enom read.
$incompleteUser=commerceUser();$incompleteUser->update(['email'=>'contact-owner@example.com']);$incompleteCart=commerceCart($incompleteUser);commerceAdd($incompleteCart,$product,placeholderComFixtureName('contacts-recovery-placeholder.com'));
commerceLegacyCheckout($incompleteUser,$incompleteCart)->assertHasNoErrors();$contactOp=Domain::where('domain','contacts-recovery-placeholder.com')->sole()->operations()->sole();
App\Helpers\ExtensionHelper::addPayment($contactOp->invoice_id,null,$contactOp->amount);
same('paid',App\Models\Invoice::findOrFail($contactOp->invoice_id)->status,'Contact correction case receives native payment');
expect($a->fresh()->processing_enabled,'Contact recovery fixture has processing enabled');expect($ports->accepted($a->fresh()),'Contact recovery fixture has accepted TEST runtime');
expect(app(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->current($contactOp->fresh()),'Contact recovery fixture is dispatchable');
expect(Paymenter\Extensions\Servers\Domains\Services\AcquisitionSetup::ready($contactOp->fresh()),'Contact recovery fixture has valid purchase inputs');
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($contactOp->id);same($before,count($GLOBALS['enomWire']['forms']),'Missing contacts stop before Enom reads');
same(['held','contact_details_required'],[$contactOp->fresh()->state,$contactOp->fresh()->safe_code],'Claim preserves the contact-specific recovery hold: '.json_encode([$contactOp->fresh()->state,$contactOp->fresh()->safe_code]));
foreach(['address'=>'1 Corrected Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$incompleteUser->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
\Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::replace($incompleteUser,$contactOp->id,'Owner corrected missing address and phone');
same('queued',$contactOp->fresh()->state,'Contact correction queues the unsent registration');$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$before=count($GLOBALS['enomWire']['forms']);$worker->operation($contactOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Corrected contact order resumes through one Purchase without pre-proof nameserver reads');

$nativeSuccessful=true;
