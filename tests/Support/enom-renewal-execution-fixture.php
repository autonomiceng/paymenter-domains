<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache,DB};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
use Paymenter\Extensions\Servers\Domains\Services\{RuntimePorts,RuntimeWork};
$ops=[];foreach($holders as $case=>$domain){if($case!=='issuance'){$ops[$case]=$make($domain);}}

config(['domains.enom_writes.registration'=>false]);
$s=(object)['name'=>'','after'=>false,'price'=>'10.00','auto'=>'0','expiry'=>'10/21/2027 5:00:00 AM','day'=>'10/21/2027','bill'=>'','order'=>'9002','lost'=>false,'confirmed'=>'2028-10-21','charge'=>'10.00','orders'=>[]];
require __DIR__.'/enom-renewal-wire.php';
$start=static function(string $case)use($s,$ops,$clock,$renewReply,$server):void {
    $s->name=$ops[$case]->domain->domain;$s->after=false;$s->auto='0';$s->price='10.00';$s->expiry='10/21/2027 5:00:00 AM';$s->day='10/21/2027';
    $clock->at=new DateTimeImmutable('2027-10-20T12:00:00Z');Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();resetWire(['reply'=>$renewReply]);
};
