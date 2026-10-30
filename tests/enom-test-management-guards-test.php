<?php
// TEST policy and unsent-evidence boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestManagement as Policy,EnomTestSendLimitSchema,EnomTestWriteReservation as Reservation,EnomTestCallLimit,ManagementEvidence,DomainManagement,WriteFeatures};
app()->instance(Clock::class,$clock);EnomTestSendLimitSchema::install();
[$d,$driver]=$holding(placeholderComFixtureName('management-test-policy-placeholder.com'));$b=$driver->capabilities()->binding;
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.management'=>true,'domains.enom_test_sends_per_day'=>1000]);
$setting=$product->server->settings()->firstOrNew(['key'=>Policy::KEY]);$setting->encrypted=true;$setting->type='string';$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...Policy::protocol()]);$setting->save();
foreach([false,'true',1,null] as $off){config(['domains.enom_writes.management'=>$off]);expect(!Policy::enabled($b),'Strict boolean opt-in');}config(['domains.enom_writes.management'=>true]);
$stored=$setting->fresh()->value;
foreach([['encrypted'=>false,'value'=>$stored],['encrypted'=>true,'value'=>str_replace($b->fingerprint,str_repeat('0',64),$stored)],['encrypted'=>true,'value'=>str_replace('25','26',$stored)],['encrypted'=>true,'value'=>'null']] as $bad){$setting->fill($bad)->save();expect(!Policy::accepted($b),'Only exact encrypted fingerprint and protocol acceptance');}$setting->fill(['encrypted'=>true,'value'=>$stored])->save();
expect(Policy::accepted($b),'Exact encrypted protocol acceptance');Policy::requireAction($b,'lock');Policy::requireAction($b,'contacts');commerceReject(fn()=>Policy::requireAction($b,'auto_renew_off'));
$engine=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true,testManagement:true);
foreach(['domains.enom_writes.management'=>false,'domains.enom_catalogue.enabled'=>false,'domains.enom_identity.environment'=>'live'] as $key=>$value){$old=config($key);config([$key=>$value]);commerceReject(fn()=>$engine->request($user,$d->id,'lock',['locked'=>false],hash('sha256',$key),$driver));config([$key=>$old]);}
$op=$engine->request($user,$d->id,'lock',['locked'=>false],hash('sha256','proof'),$driver);$valid=['seal'=>'sealed','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32),'management_test_unsent'=>true];
// A copied marker never creates proof without the exact live operation reservation.
$op->state='held';$op->safe_code='enom_test_management_call_cap';$op->attempt=$valid;commerceReject(fn()=>ManagementEvidence::guard($op));$op->refresh();
$refuse=static function(Closure $f):void{try{$f();throw new LogicException('Reservation should refuse');}catch(EnomTestCallLimit){}};
Reservation::run($b,'management',(int)$op->id,function()use($b,$op,$valid,$refuse){
    expect(!Reservation::unconsumed($b,'management',(int)$op->id),'Reservation must exist before unsent proof');Reservation::reserve($b,'management');
    expect(Reservation::unconsumed($b,'management',(int)$op->id),'Exact live unconsumed reservation');expect(!Reservation::unconsumed($b,'renewal',(int)$op->id)&&!Reservation::unconsumed($b,'management',(int)$op->id+1),'Action and operation bound');$wrong=new AccountBinding('enom',$b->serverId+1,Environment::Test,'another-account');expect(!Reservation::unconsumed($wrong,'management',(int)$op->id),'Reservation fingerprint bound');
    $op->state='held';$op->safe_code='enom_test_management_call_cap';$op->attempt=$valid;
    commerceReject(fn()=>ManagementEvidence::guard($op)); // no original sealed transition
    $op->attempt=['seal'=>'sealed','send_state'=>'possibly_sent'];$op->syncOriginalAttribute('attempt');
    $op->attempt=$valid;ManagementEvidence::guard($op);
    foreach(['management_test_unsent'=>false,'seal'=>'absent','send_state'=>'possibly_sent','management_reads'=>null,'management_ack'=>null] as $key=>$value){$op->attempt=[$key=>$value]+$valid;commerceReject(fn()=>ManagementEvidence::guard($op));}
    $op->attempt=$valid;expect(ManagementEvidence::idle($op),'Only clean nonfinancial proof is idle');
    foreach(['management_reads'=>[],'management_ack'=>[]] as $key=>$value){$op->attempt=[$key=>$value]+$valid;expect(!ManagementEvidence::idle($op),'Read or acknowledgement cannot be proven unsent');}
    $op->attempt=$valid;$op->provider_order_id='900';commerceReject(fn()=>ManagementEvidence::guard($op));$op->provider_order_id=null;
    foreach(['state'=>'uncertain','safe_code'=>'other','funding_basis'=>'paid_invoice'] as $key=>$value){$old=$op->$key;$op->$key=$value;commerceReject(fn()=>ManagementEvidence::guard($op));$op->$key=$old;}
    $day=Reservation::prepare($b,'management');same('',$day,'TEST arms the scope without a day');Reservation::consume($b,'management',$day);expect(!Reservation::unconsumed($b,'management',(int)$op->id),'Consumed wire cannot become unsent');commerceReject(fn()=>ManagementEvidence::guard($op));
    $refuse(fn()=>Reservation::reserve($b,'management'));expect(!Reservation::unconsumed($b,'management',(int)$op->id),'Consumed is sticky for the scope');
});
same(0,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'Arming and consuming the scope record no send; the limiter counts at the wire');
$op->attempt=$valid;$op->syncOriginalAttribute('attempt');$op->attempt=array_diff_key($valid,['management_test_unsent'=>true]);commerceReject(fn()=>ManagementEvidence::guard($op));
echo "TEST management current policy and unsent-proof guards passed\n";$nativeSuccessful=true;
