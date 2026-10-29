<?php
// Separate installed continuation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{RuntimeWork,RuntimePorts};
$data=json_decode(file_get_contents($receipt.'.transfer'),true,512,JSON_THROW_ON_ERROR);$op=Operation::findOrFail($data['operation']);
$clock=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2026-10-22T02:00:00Z');}};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);config(['domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
$transferState=(object)['name'=>$op->domain->domain,'bill'=>$data['bill'],'pending'=>false,'charge'=>true];require __DIR__.'/enom-transfer-wire.php';
\Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$transferReply]);Cache::flush();$worker=new RuntimeWork($clock,new RuntimePorts);$worker->operation($op->id);
same('succeeded',$op->fresh()->state,'Restart confirms original transfer');same(['GetDomainInfo','GetRenew','GetRegLock','GetWPPSInfo','GetDomainInfo','GetDomainExp','TP_GetOrder','GetOrderList','GetOrderDetail','GetDNS'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Restart reads nameservers after completed transfer proof');same(['ns1.provider.example','ns2.provider.example'],$op->domain->fresh()->observation['nameservers']['nameservers']??null,'Transfer restart stores nameservers as observed');
$worker->operation($op->id);same(10,count($GLOBALS['enomWire']['forms']),'Duplicate restart sends nothing');same($data['credits'],DB::table('credits')->count(),'Restart adds no credit');echo "Installed TEST transfer restart read-only completion passed.\n";
