<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native ordinary-worker closure; no provider network.
declare(strict_types=1);
$fixtureEnding='example.com';require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/registration-refusal-reply.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Role,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,Refunds,RuntimePorts,RuntimeWork};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Complimentary funding','permissions'=>['admin.domains.fund','admin.domains.view','admin.domains.manage','admin.domains.costs']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Synthetic complimentary refusal',$clock);
foreach(['RuntimeSchema','ReminderSchema','ObservationSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
$binding=$bindingFor(0);$calls=[];$controls=[];
foreach(['unknown','lost','contradictory','missing_proof','foreign_proof','invalid_receipt'] as $case){
    $makeEngine($binding,static function($origin,$command,$parameters,$timeout)use($case,$purchaseRefusal):string {
        if($case==='lost'){throw new RuntimeException('Synthetic lost reply');}
        return match($case){
            'unknown'=>str_replace(['541','Validation error. Failed to create order.'],['545','Error 545 - Name server does not exist'],$purchaseRefusal),
            'contradictory'=>str_replace('</interface-response>','<OrderID>1234</OrderID></interface-response>',$purchaseRefusal),
            default=>$purchaseRefusal};
    });
    $bad=$runRegistration($new(str_replace('_','-',$case).'.example.com',false));
    if(in_array($case,['missing_proof','foreign_proof'],true)){
        $attempt=$bad->attempt;
        if($case==='missing_proof'){unset($attempt['evidence']['registrationRefusal']);}
        else{$attempt['evidence']['registrationRefusal']['record']['claim_key']='foreign';}
        // Test-only corruption of retained encrypted facts, never a production bypass.
        DB::table('domains_operations')->where('id',$bad->id)->update(['attempt'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($attempt))]);
    }
    if($case==='invalid_receipt'){DB::table('invoice_items')->where('id',$bad->invoice_item_id)->update(['price'=>'1.00']);}
    $controls[$case]=[$bad->id,$bad->service_id,Service::findOrFail($bad->service_id)->status];
}
$binding=$bindingFor(0);$calls=[];
$makeEngine($binding,static function($origin,$command,$parameters,$timeout)use(&$calls,$purchaseRefusal):string{$calls[]=$command;return $purchaseRefusal;});
$op=$new('comp-refusal.example.com',false);$result=$runRegistration($op);
same(['rejected','complimentary'],[$result->state,$result->funding_basis],'Real narrow 541 refusal retains complimentary funding');
$runtime=new RuntimeWork($clock,new RuntimePorts);app()->instance(Refunds::class,new Refunds($clock));
$money=static fn()=>array_map(static fn($table)=>DB::table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all(),['invoices','invoice_items','invoice_transactions','credits']);
$before=$money();
// A missing native service is not a complimentary credit obligation in either selector.
$nativeService=$op->service_id;DB::table('domains_operations')->where('id',$op->id)->update(['service_id'=>999999]);
$missing=$op->fresh();
same(false,Paymenter\Extensions\Servers\Domains\Services\RefundCandidates::query(Paymenter\Extensions\Servers\Domains\Models\Operation::whereKey($op->id))->exists(),'Missing native service is absent from discovery');
$missingMatches=Paymenter\Extensions\Servers\Domains\Services\RefundCandidates::matches($missing,$missing->funding);
require __DIR__.'/Support/staff-panel.php';staffPanel();
$details=Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])->assertSuccessful();
if(!$missingMatches){$details->assertDontSee('No credit added');}
expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::creditReview(Paymenter\Extensions\Servers\Domains\Models\Domain::whereKey($op->domain_id))->exists(),'Missing service leaves the real credit-review filter');
DB::table('domains_operations')->where('id',$op->id)->update(['service_id'=>$nativeService]);
same(false,$missingMatches,'Missing native service also has no staff credit obligation');
$runtime->tick();
same('cancelled',Service::findOrFail($op->service_id)->status,'Ordinary runtime discovery and refund dispatch close the proved complimentary refusal');
same('registration_rejected',$op->domain->fresh()->status,'Automatic closure preserves the refusal');
same(['paid',0,0],[Invoice::findOrFail($op->invoice_id)->status,InvoiceTransaction::count(),Credit::count()],'Zero receipt stays Paid without transactions or credit');
expect(!Paymenter\Extensions\Servers\Domains\Services\RefundCandidates::query(Paymenter\Extensions\Servers\Domains\Models\Operation::whereKey($op->id))->exists(),'Completed complimentary closure leaves the runtime refund candidates');
expect(!Paymenter\Extensions\Servers\Domains\Services\RefundCandidates::matches($op->fresh(),$op->fresh()->funding),'Completed complimentary closure is not a staff credit obligation');
expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::creditReview(Paymenter\Extensions\Servers\Domains\Models\Domain::whereKey($op->domain_id))->exists(),'Completed closure leaves the real credit-review filter');
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])->assertDontSee('No credit added')->assertSee('No paid invoice recorded');
$refundLocks=0;DB::listen(static function($q)use(&$refundLocks,$op):void{if(!str_contains($q->sql,'from "domains_domains"')||!in_array($op->domain_id,$q->bindings,true)){return;}foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,65)as$frame){if(($frame['class']??null)===Refunds::class&&($frame['function']??null)==='creditRejected'){$refundLocks++;break;}}});
$evidence=static fn()=>array_intersect_key($op->fresh()->getAttributes(),array_flip(['state','safe_code','attempt','funding']));$closed=$evidence();$serviceClosed=Service::findOrFail($op->service_id)->getRawOriginal();$clock->instant=$clock->now()->modify('+1 minute');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());$runtime->tick();
same($serviceClosed,Service::findOrFail($op->service_id)->getRawOriginal(),'Later worker replay does not rewrite the closed native service');same($closed,$evidence(),'Repeated worker closure retains original evidence; native closure bookkeeping may be recorded separately');
foreach($controls as $case=>[$id,$serviceId,$status]){same($status,Service::findOrFail($serviceId)->status,'Unproved or invalid complimentary order stays open: '.$case);expect(!isset(\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($id)->funding['refund']),'No refund authority from '.$case);}
same(0,$refundLocks,'A later runtime tick never re-locks the already closed complimentary holding');
same($before,$money(),'Worker closure and replay change no money history');same(['Purchase'],$calls,'Worker never resends the refused registration');
echo "Native complimentary refusal worker closure passed\n";$nativeSuccessful=true;
