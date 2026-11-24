<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/../native-app.php';
require_once __DIR__.'/staff-panel.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\DnsTemplate;
function dnsFixture(): array
{
    $entry = new Paymenter\Extensions\Servers\Domains\Domains([]); $entry->installed();
    $owner = staffUser([]); $other = staffUser([]);
    Currency::firstOrCreate(['code'=>'USD'], ['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
    $server = Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
    $profile = DB::transaction(fn()=>DnsTemplate::create(['name'=>'Synthetic defaults','nameservers'=>['NS2.platform.test.','ns1.platform.test'],
        'edited_by'=>$owner->id,'reason'=>'Synthetic composition']));
    return compact('entry','owner','other','server','profile');
}
