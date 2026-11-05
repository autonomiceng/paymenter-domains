<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The affected boundary is the persisted request's staff label and native Filament page.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
staffPanel();[, $d]=staffData(true);$actor=staffUser([Staff::VIEW]);Auth::setUser($actor);
$op=Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
    'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>'staff-refused-nameservers',
    'action'=>'nameservers','funding_basis'=>'non_billable_management','currency'=>'USD','amount'=>'0.00','state'=>'rejected',
    'safe_code'=>'management_provider_refused','policy'=>['management'=>['input'=>['nameservers'=>['ns1.example.net','ns2.example.net']]]]]);
$expected='The registrar didn’t accept these nameservers. Check them or contact support.';
same($expected,Labels::operationReason($op),'Staff request label matches the shared nameserver refusal guidance');
same($expected,RequestFacts::status($op,true,$d)['reason'],'Staff request status keeps the same next step');
Livewire\Livewire::actingAs($actor)->test(ViewOperation::class,['record'=>$op->id])
    ->assertSee($expected)->assertDontSee('The customer can try again.');
$nativeSuccessful=true;echo "Staff nameserver refusal label gives one consistent next step.\n";
