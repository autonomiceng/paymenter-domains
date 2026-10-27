<?php
// Separate installed PHP continuation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{RuntimePorts,RuntimeWork};
$data=json_decode(file_get_contents($receipt.'.management'),true,512,JSON_THROW_ON_ERROR);$op=Operation::findOrFail($data['operation']);
$clock=new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2026-10-22T02:00:00Z');}};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);config(['domains.enom_test_daily_call_cap'=>1000]);
$s=(object)['ns'=>['ns7.example.com','ns8.example.com'],'locked'=>false,'dns'=>[],'fault'=>'','owner'=>'test-uid','id'=>'456'];$reply=static fn()=>throw new LogicException('Unexpected restart command');require __DIR__.'/enom-management-wire.php';
Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$manageReply]);Illuminate\Support\Facades\Cache::flush();
$worker=new RuntimeWork($clock,new RuntimePorts);$worker->operation($op->id);same('succeeded',$op->fresh()->state,'New process confirms retained desired state');same(['GetDomainInfo','GetDNS'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Restart only reads, no second write');
$worker->operation($op->id);same(2,count($GLOBALS['enomWire']['forms']),'Completed duplicate restart makes no call');
$money=array_map(static fn($t)=>Illuminate\Support\Facades\DB::table($t)->orderBy('id')->get()->map(static fn($v)=>(array)$v)->all(),['invoices','invoice_items','invoice_transactions','credits']);same($data['money'],$money,'Restart preserves money');
echo "Installed TEST management restart passed.\n";
