<?php
// Unicode review reasons at the actual staff writer; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Livewire\Livewire;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperations;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffReason};
use Illuminate\Support\Facades\{DB,Event};
use Illuminate\Log\Events\MessageLogged;
$logs=[];Event::listen(MessageLogged::class,static function($e)use(&$logs):void{$logs[]=['level'=>$e->level,'message'=>$e->message,'context'=>$e->context];});
$op=$held('staff-unicode-reason-placeholder.com');
$write=fn(string $reason)=>$service->run($staff,$op->domain_id,$op->id,'release',$ticket($op->fresh(),'release'),$reason);
foreach([str_repeat('é',4),str_repeat('é',501),"abcde\xff","line\nbreak"] as $bad){
    $before=$op->fresh()->getRawOriginal();commerceReject(fn()=>$write($bad));same($before,$op->fresh()->getRawOriginal(),'Invalid Unicode reason changes nothing');
}
$reason=str_repeat('🦦',500);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('refund',['reason'=>$reason])->assertHasNoActionErrors()->assertNotified('Cancelled and refunded');
same($reason,$op->fresh()->policy['staff_actions'][0]['reason'],'500 Unicode characters retained');
// Release hold has no reason field and records its own name.
$op=$held('staff-release-name-placeholder.com');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
same('Release hold',$op->fresh()->policy['staff_actions'][0]['reason'],'Release records its action name');
// Server refusal text is safe and meaningful, even when called without form validation.
$op=$held('staff-unicode-server-placeholder.com');
try{$write('éééé');throw new RuntimeException('Expected character-length refusal.');}
catch(DomainException $e){same('Enter a one-line reason between 5 and 500 characters.',$e->getMessage(),'Character unit in safe refusal');}
\Illuminate\Support\Facades\Auth::setUser($staff);
$action=collect(OperationActions::make($op->fresh(),StaffOperationReview::stamp($op->fresh(),$op->domain->fresh())))->first(fn($a)=>$a->getName()==='refund');
($action->getActionFunction())(['review_key'=>$ticket($op,'refund'),'reason'=>'éééé']);
Notification::assertNotified(Notification::make()->danger()->title("Couldn't update request")->body('Enter a one-line reason between 5 and 500 characters.'));
same([],$logs,'Expected staff validation remains quiet');
// Exercise the actual source guards through the staff action, without provider traffic.
$refuse=static function($op,string $name,string $code,string $message)use(&$logs,$ticket,$staff):string{
    \Illuminate\Support\Facades\Auth::setUser($staff);$logs=[];
    $action=collect(OperationActions::make($op,StaffOperationReview::stamp($op,$op->domain->fresh())))->first(fn($a)=>$a->getName()===$name);
    $key=$ticket($op,$name);($action->getActionFunction())(['review_key'=>$key,'reason'=>'Reviewed original evidence']);
    Notification::assertNotified(Notification::make()->danger()->title("Couldn't update request")->body($message));
    same([['level'=>'warning','message'=>'Domains staff operation refused.','context'=>['reason'=>$code,'action'=>$name,'operation_id'=>(int)$op->id]]],$logs,'Exactly one warning with only safe diagnostic fields');
    return $key;
};
$evidence=$held('staff-provider-evidence-placeholder.com');$evidence->domain->update(['provider_domain_id'=>'synthetic-private-id']);
$refuse($evidence,'refund','acquisition_provider_evidence',"Can't refund: the registrar may have received this request.");
$line=$held('staff-no-paid-line-placeholder.com');DB::table('domains_operations')->where('id',$line->id)->update(['funding_basis'=>'offline_demo']);$line->refresh();
$refuse($line,'release','no_eligible_paid_line',"Can't refund: no paid invoice for this request.");
$missing=$held('staff-missing-invoice-placeholder.com');DB::table('domains_operations')->where('id',$missing->id)->update(['invoice_id'=>999999]);$missing->refresh();
$before=[$missing->attempt,$missing->funding,$missing->domain->fresh()->getRawOriginal(),$credit(),count($observation->calls),$state->writes];
$key=$refuse($missing,'recheck','original_invoice_missing',"Can't refund: invoice not found.");
$history=$missing->fresh()->policy['staff_actions'];
same(['read_requested','read_failed'],array_column($history,'phase'),'Failed read never claims to have finished');
same('original_invoice_missing',$history[1]['safe_code'],'Failed read retains its bounded reason');
same($before,[$missing->fresh()->attempt,$missing->fresh()->funding,$missing->domain->fresh()->getRawOriginal(),$credit(),count($observation->calls),$state->writes],'Failed recheck preserves evidence, money and transport counts');
commerceReject(fn()=>$service->run($staff,$missing->domain_id,$missing->id,'recheck',$key,'Reviewed original evidence'));
same(2,count($missing->fresh()->policy['staff_actions']),'Consumed failed-read key cannot be reused');
foreach([new DomainException('PRIVATE-PROVIDER-SENTINEL'),new RuntimeException('PRIVATE-RUNTIME-SENTINEL'),
    (new Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(App\Models\User::class,['PRIVATE-CUSTOMER'])] as $error){
    app()->instance(StaffOperations::class,new class($error){
        public function __construct(private Throwable $error){}
        public function run(...$args):void{throw $this->error;}
    });
    $code=$error instanceof DomainException?'guard_refused':($error instanceof Illuminate\Database\Eloquent\ModelNotFoundException?'native_record_missing':'producer_failed');
    $refuse($op,'release',$code,'Reload the page and try again. Ref '.$code.'.');
    expect(!str_contains(json_encode($logs),'PRIVATE-')&&!str_contains(json_encode($logs),$error::class),'No exception message or class logged');
    expect(!str_contains(json_encode(session()->get('filament.notifications',[])),'PRIVATE-'),'Raw exception text never shown');
}
app()->instance(StaffOperations::class,$service);
$unknown=$held('staff-read-unknown-placeholder.com');$error=new RuntimeException('PRIVATE-READ-SENTINEL');
$review=new StaffOperationReview($staff->id,$unknown->domain_id,$unknown->id,'recheck',$ticket($unknown,'recheck'),'Reviewed original evidence',$clock);
try{$review->read(fn()=>throw $error);expect(false,'Unknown read must propagate');}
catch(RuntimeException $caught){same($error,$caught,'Read propagates the original throwable');}
$history=$unknown->fresh()->policy['staff_actions'];
same(['read_requested','read_failed'],array_column($history,'phase'),'Unknown failed read never claims success');
same('producer_failed',$history[1]['safe_code'],'Unknown failed read uses a closed reason');
expect(!str_contains(json_encode($history),'PRIVATE-')&&!str_contains(json_encode($history),RuntimeException::class),'Read audit contains no raw exception');
// Shared validator preserves the distinct max-only adoption/PSL and ten-character resend rules.
foreach([1,5,10] as $min){
    StaffReason::require(str_repeat('é',$min),$min,500);
    StaffReason::require(str_repeat('🦦',500),$min,500);
    commerceReject(fn()=>StaffReason::require(str_repeat('é',$min-1),$min,500));
    commerceReject(fn()=>StaffReason::require(str_repeat('é',501),$min,500));
    commerceReject(fn()=>StaffReason::require("abcde\xff",$min,500));
}
echo "Staff Unicode reason boundaries passed.\n";
$nativeSuccessful=true;
