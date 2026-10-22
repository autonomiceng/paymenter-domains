<?php
// A fresh installed PHP process continues the retained Extend; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCalls,EnomTestCallSchema,RuntimePorts,RuntimeWork};
$data=json_decode(file_get_contents($receipt.'.renewal'),true,512,JSON_THROW_ON_ERROR);$op=Operation::findOrFail($data['operation']);same('pending',$op->state,'Previous process retained pending renewal');
$clock=new class implements Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2027-10-20T13:00:00Z');}};
app()->instance(Clock::class,$clock);config(['domains.enom_test_daily_call_cap'=>1000]);
$s=(object)['name'=>$op->domain->domain,'after'=>true,'auto'=>'0','expiry'=>'10/21/2027','day'=>'10/21/2027','price'=>'10.00','order'=>'9002','confirmed'=>'2028-10-21','charge'=>'10.00','orders'=>[],
    'bill'=>(new DateTimeImmutable($op->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A')];
require __DIR__.'/enom-renewal-wire.php';Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$renewReply]);Cache::flush();
$worker=new RuntimeWork($clock,new RuntimePorts);$worker->operation($op->id);same('succeeded',$op->fresh()->state,'New process proves retained charge and year expiry');
same(5,count($GLOBALS['enomWire']['forms']),'Process continuation reads exactly five times');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Process restart never resends');
same($data['used']+5,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Call reservations survive actual process restart');
$money=array_map(static fn($table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),['invoices','invoice_items','invoice_transactions','credits']);same($data['money'],$money,'Restart keeps native money unchanged');
$worker->operation($op->id);same(5,count($GLOBALS['enomWire']['forms']),'Repeated completed continuation makes no call');
echo "Installed TEST renewal continuation passed in a separate PHP process.\n";
