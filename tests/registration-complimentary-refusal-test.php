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
$closed=$op->fresh()->getRawOriginal();$runtime->tick();same($closed,$op->fresh()->getRawOriginal(),'Repeated worker closure retains original evidence');
same($before,$money(),'Worker closure and replay change no money history');same(['Purchase'],$calls,'Worker never resends the refused registration');
echo "Native complimentary refusal worker closure passed\n";$nativeSuccessful=true;
