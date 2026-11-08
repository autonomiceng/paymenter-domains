<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native ordinary-worker closure; no provider network.
declare(strict_types=1);
$fixtureEnding='example.com';require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/registration-refusal-reply.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Role,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,Refunds,RuntimePorts,RuntimeWork};
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Complimentary funding','permissions'=>['admin.domains.fund']])->id]);
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
$before=$money();$runtime->tick();
same('cancelled',Service::findOrFail($op->service_id)->status,'Ordinary runtime discovery and refund dispatch close the proved complimentary refusal');
same('registration_rejected',$op->domain->fresh()->status,'Automatic closure preserves the refusal');
same(['paid',0,0],[Invoice::findOrFail($op->invoice_id)->status,InvoiceTransaction::count(),Credit::count()],'Zero receipt stays Paid without transactions or credit');
$closed=$op->fresh()->getRawOriginal();$serviceClosed=Service::findOrFail($op->service_id)->getRawOriginal();$clock->instant=$clock->now()->modify('+1 minute');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());$runtime->tick();
same($serviceClosed,Service::findOrFail($op->service_id)->getRawOriginal(),'Later worker replay does not rewrite the closed native service');same($closed,$op->fresh()->getRawOriginal(),'Repeated worker closure retains original evidence');
foreach($controls as $case=>[$id,$serviceId,$status]){same($status,Service::findOrFail($serviceId)->status,'Unproved or invalid complimentary order stays open: '.$case);expect(!isset(\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($id)->funding['refund']),'No refund authority from '.$case);}
same($before,$money(),'Worker closure and replay change no money history');same(['Purchase'],$calls,'Worker never resends the refused registration');
echo "Native complimentary refusal worker closure passed\n";$nativeSuccessful=true;
