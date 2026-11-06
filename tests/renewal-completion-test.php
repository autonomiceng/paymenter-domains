<?php
// Enom d1392a8 grace and covered renewal observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomLateRenewal, EnomTestRenewal, EnomTestSendLimitSchema, RenewalRefused};
// Grace requires the current account opt-in and a paid native late quote, not the old constructor flag.
[$old,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-grace-placeholder.com'),false);
$clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z');
$cycles->expireUnpaid($d->id);
same('renewal_cancelled_unpaid',$old->fresh()->safe_code,'Unpaid ordinary debt closes before the late quote');
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);EnomTestSendLimitSchema::install();
$deployment=[];foreach(['domains.enom_identity.environment','domains.enom_catalogue.enabled','domains.enom_writes.renewal','domains.enom_test_sends_per_day','domains.enom_test_sends_per_minute'] as $key){$deployment[$key]=config($key);}
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_test_sends_per_day'=>10000,'domains.enom_test_sends_per_minute'=>10000]);
$server=$product->server;
$server->settings()->create(['key'=>EnomTestRenewal::KEY,'type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol($b)])]);
$server->settings()->create(['key'=>EnomLateRenewal::KEY,'type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$b->fingerprint,'contract'=>EnomLateRenewal::CONTRACT])]);
Cache::flush();$invoice=(new DomainBilling($renewBindings,$guard,$clock,catalogue: $GLOBALS['caps']??null))->invoice($d->id,$user,1);
$op=Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($invoice,null,$op->amount);Cache::flush();[$engine,$writer,$completion]=$compose($b);
$writes=$state->writes;$state->fail=ReadCommand::Expired;
same('queued',$engine->run($op->id,$writer)->state,'Grace expiry read outage stays retryable');
same('renewal_reads_unavailable',$op->fresh()->safe_code,'Grace expiry outage retains its typed read reason');
same($writes,$state->writes,'Grace expiry outage sends nothing');
$state->fail=null;Cache::flush();
same('pending',$engine->run($op->id,$writer)->state,'Accepted grace shape retains the original nested order');
same($writes+1,$state->writes,'Grace expiry read recovery sends the paid late obligation once');
same(['UpdateExpiredDomains',['DomainName'=>$d->domain,'NumYears'=>1,'UseCreditCard'=>'no']],$state->lastWrite,'Explicit one-year grace account-balance command');
same('9002',$op->fresh()->provider_order_id,'Grace original order retained without inventing completion');
$state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Grace completes only with unique actual charge and matching full-term expiry');
config($deployment);
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-local-clock-placeholder.com')); [$engine,$writer,$completion]=$compose($b);
$engine->run($op->id,$writer); $state->expiry='2028-10-18'; $state->charge='8.00'; $state->billTime='6:02:00 AM';
$completed=$completion->run($op->id);
same('succeeded',$completed->state,'Captured renewal uses the same provider-local window');
expect(\Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority::completed($completed,$d->fresh(),$b),'Renewal local proof remains usable for the next cycle');
// Hiding a TLD stops new sales only. Names already held keep renewing.
config(['domains.hidden_endings'=>['com']]);
same('staff_stopped',$ports->readOffers(new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,['com'],'register'),\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->reason,'Hidden TLD takes no new registrations');
same($d->domain,$authority->capture(new RenewalRequest($d->id,1,2,'2028-10-18','456'))[1],'Hidden TLD keeps renewing');
config(['domains.hidden_endings'=>[]]);
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-transient-placeholder.com')); [$engine,$writer,$completion]=$compose($b);
same('pending',$engine->run($op->id,$writer)->state,'Original transient cycle sent');
$state->fail=ReadCommand::Info;
for($i=0;$i<6;$i++){ $completion->run($op->id); }
same(0,$op->fresh()->policy['renewal_polls']??0,'Six failed reads do not exhaust covered observations');
$state->fail=null; $state->expiry='2028-10-18'; $state->charge='8.00';
same('succeeded',$completion->run($op->id)->state,'Valid original evidence completes after transient outage');
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-lower-actual-placeholder.com')); [$engine,$writer,$completion]=$compose($b);
$engine->run($op->id,$writer); $state->expiry='2028-10-18'; $state->charge='7.99';
same('succeeded',$completion->run($op->id)->state,'Incurred lower actual charge retained');
try { $authority->capture(new RenewalRequest($d->id,1,2,'2028-10-18','456')); throw new RuntimeException('Changed actual cost incorrectly reused'); }
catch(RenewalRefused $e){ same(RenewalCode::Cost,$e->reason,'Lower actual conservatively refuses future cost authority'); }
$clock->instant=new DateTimeImmutable('2028-09-27T12:00:00Z');
$count=App\Models\Invoice::count(); commerceReject(fn()=>$billing->invoice($d->id,$user)); same($count,App\Models\Invoice::count(),'Changed actual cost refuses new debt before native writes');
echo "Grace acknowledgement, outage recovery and actual-cost retention passed\n"; $nativeSuccessful=true;
