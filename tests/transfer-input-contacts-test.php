<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-fixture.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// Contact correction preserves independent transfer inputs and remains held.
$contacts=$new('transfer-contacts.com'); $inputs->paymentAccepted($contacts->id); $inputs->submitCode($post($user,'preserve'),$contacts->id);
$contacts->refresh()->update(['payload'=>[...$contacts->payload,'nameservers'=>['ns1.example.com','ns2.example.com']]]);
ContactRecovery::hold($contacts->id); $before=$contacts->fresh();
$user->update(['email'=>'transfer-owner@example.com']);
foreach(['address'=>'1 Example St','city'=>'Example','state'=>'CA','zip'=>'90001','country'=>'US','phone'=>'+1.2025550100'] as $k=>$v){$user->properties()->updateOrCreate(['key'=>$k],['value'=>$v]);}
ContactRecovery::replace($user,$contacts->id,'Owner corrected contact details'); $after=$contacts->fresh();
same($before->payload['epp'],$after->payload['epp'],'Contact correction preserves code');same($before->policy['paid_at'],$after->policy['paid_at'],'Contact correction preserves paid clock');
same($before->payload['registry'],$after->payload['registry'],'Contact correction preserves registry');same('held',$after->state,'Contact correction remains held');
same($before->payload['nameservers']??null,$after->payload['nameservers']??null,'Contact correction preserves nameservers');
$clock->instant=$now->modify('+30 days'); Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
ContactSetup::recoveryWrite(fn()=>$after->update(['safe_code'=>'contact_details_required']));
$lateBefore=$after->fresh();
ContactRecovery::replace($user,$after->id,'Late contact correction reviewed');
same('transfer_setup_review_required',$after->fresh()->safe_code,'Late saved-code correction retains staff gate');
same($lateBefore->payload['epp'],$after->fresh()->payload['epp'],'Late correction keeps the exact saved code');
require __DIR__.'/Support/late-contact-matrix.php';
echo "Transfer contact correction and late readmission checks passed.\n";
$nativeSuccessful=true;
