<?php
// Native late-renewal automatic collection and consent; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/late-renewal.php';
use App\Models\{BillingAgreement,Gateway,Invoice};
use Illuminate\Support\Facades\{Cache,Mail};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomLateRenewal,EnomTestRenewal,EnomTestSendLimitSchema,PricingRule,RenewalAutoPay,StaffSettingsReview};
// Consent and one-attempt collection use the existing native gateway route.
class LateRenewalGateway {
    public static int $calls=0;
    public function __construct(array $settings){}
    public function charge(Invoice $invoice,$amount,BillingAgreement $agreement):bool {
        same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'Collection is outside locks');self::$calls++;return false;
    }
}
class_alias(LateRenewalGateway::class,'Paymenter\\Extensions\\Gateways\\LateDouble\\LateDouble');
$gateway=Gateway::create(['name'=>'Synthetic late card','extension'=>'LateDouble','type'=>'gateway','enabled'=>true]);
$agreement=BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Synthetic card','external_reference'=>'synthetic-only']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
[$auto,$ab]=$holder('auto');$toggle(true);$auto->service->update(['billing_agreement_id'=>$agreement->id]);$state->price='12.00';Cache::flush();
$ai=$lateBilling->invoice($auto->id);$ao=Operation::where('invoice_id',$ai->id)->sole();same(1,LateRenewalGateway::$calls,'Scheduled late invoice tries saved method once');same('declined',$ao->funding['auto_pay_result']['status'],'Definite decline retained');
$lateBilling->invoice($auto->id);same(1,LateRenewalGateway::$calls,'Scheduler rerun never retries a decline');same(null,(new RenewalAutoPay($clock))->renewalAutoPayContext($ai->id),'Overdue invoice grants no gateway retry context');
$clock->instant=new DateTimeImmutable($ao->policy['renewal']['late']['deadline']);$cycles->expireUnpaid($auto->id);same('cancelled',$ai->fresh()->status,'Untouched declined invoice closes at precise deadline');
commerceReject(fn()=>$lateBilling->invoice($auto->id));same(1,LateRenewalGateway::$calls,'Closed late cycle cannot produce a second debit');
[$off,$ob]=$holder('consent');$off->service->update(['billing_agreement_id'=>$agreement->id]);$off->update(['renewal_policy'=>[...$off->renewal_policy,'owner_auto_renew'=>false]]);Cache::flush();$state->calls=[];same(null,$lateBilling->invoice($off->id),'Owner Off stops scheduled late billing');same([],$state->calls,'Owner Off sends no fresh reads');same(1,LateRenewalGateway::$calls,'Owner Off never debits');$oi=$lateBilling->invoice($off->id,$user,1);$oo=Operation::where('invoice_id',$oi->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($oi,null,$oo->amount);$clock->instant=new DateTimeImmutable($oo->policy['renewal']['late']['deadline']);Cache::flush();[$oe,$ow]=$compose($ob);$before=$state->writes;$oe->run($oo->id,$ow);same($before,$state->writes,'No deadline-late provider write');
// Expiry cannot turn an earlier ordinary Auto Pay decline into a new debit attempt.
config($checkout);[$ordinary,$prior,$pb]=$makeRenewal(placeholderComFixtureName('late-prior-decline-placeholder.com'),false);$cycles->retireUnpaid($ordinary->id);
$prior->service->update(['billing_agreement_id'=>$agreement->id]);$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$before=LateRenewalGateway::$calls;$pi=$lateBilling->invoice($prior->id);same($before+1,LateRenewalGateway::$calls,'Ordinary cycle makes its one saved-method attempt');
$clock->instant=new DateTimeImmutable('2027-10-19T12:00:00Z');$cycles->expireUnpaid($prior->id);same('cancelled',$pi->fresh()->status,'Definite ordinary decline closes at expiry');config($on);Cache::flush();$state->calls=[];
commerceReject(fn()=>$lateBilling->invoice($prior->id));same([],$state->calls,'Prior debit stops scheduled late replacement before reads');same($before+1,LateRenewalGateway::$calls,'Late renewal cannot retry an ordinary decline');
$lateBilling->invoice($prior->id,$user,1);same($before+1,LateRenewalGateway::$calls,'Owner can request a late invoice without another automatic debit');
$toggle(false);expect(!EnomLateRenewal::accepted($ab),'Account switch can be turned Off');
echo "Late renewal one debit and owner consent passed\n";$nativeSuccessful=true;
