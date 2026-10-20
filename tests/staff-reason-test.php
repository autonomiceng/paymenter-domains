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
$op=$held('staff-unicode-reason.com');
$write=fn(string $reason)=>$service->run($staff,$op->domain_id,$op->id,'release',$ticket($op->fresh(),'release'),$reason);
foreach([str_repeat('é',4),str_repeat('é',501),"abcde\xff","line\nbreak"] as $bad){
    $before=$op->fresh()->getRawOriginal();commerceReject(fn()=>$write($bad));same($before,$op->fresh()->getRawOriginal(),'Invalid Unicode reason changes nothing');
}
$reason=str_repeat('🦦',500);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release',['reason'=>$reason])->assertHasNoActionErrors()->assertNotified('Review recorded');
same($reason,$op->fresh()->policy['staff_actions'][0]['reason'],'500 Unicode characters retained');
// Server refusal text is safe and meaningful, even when called without form validation.
$op=$held('staff-unicode-server.com');
try{$write('éééé');throw new RuntimeException('Expected character-length refusal.');}
catch(DomainException $e){same('Enter a one-line reason between 5 and 500 characters.',$e->getMessage(),'Character unit in safe refusal');}
\Illuminate\Support\Facades\Auth::setUser($staff);
$action=collect(OperationActions::make($op->fresh(),StaffOperationReview::stamp($op->fresh(),$op->domain->fresh())))->first(fn($a)=>$a->getName()==='release');
($action->getActionFunction())(['review_key'=>$ticket($op,'release'),'reason'=>'éééé']);
Notification::assertNotified(Notification::make()->danger()->title('Review refused')->body('Enter a one-line reason between 5 and 500 characters.'));
foreach([new DomainException('PRIVATE-PROVIDER-SENTINEL'),new RuntimeException('PRIVATE-RUNTIME-SENTINEL')] as $error){
    app()->instance(StaffOperations::class,new class($error){
        public function __construct(private Throwable $error){}
        public function run(...$args):void{throw $this->error;}
    });
    ($action->getActionFunction())(['review_key'=>$ticket($op,'release'),'reason'=>'Reviewed original evidence']);
    Notification::assertNotified(Notification::make()->danger()->title('Review refused')->body('Reload this page and review current permissions, funding and retained evidence.'));
    expect(!str_contains(json_encode(session()->get('filament.notifications',[])),'PRIVATE-'),'Raw exception text never shown');
}
app()->instance(StaffOperations::class,$service);
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
