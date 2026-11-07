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
foreach(['acquisition_definitely_unsent','management_definitely_unsent'] as $code){same(\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::UNSENT,Labels::reason($code),'Established unsent words: '.$code);}
// Synthetic retained rows isolate the projection. Transport suites prove production of these frozen codes.
$namesilo=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('registrar_key','namesilo')->firstOrFail();
foreach(['register'=>'acquisition_definitely_unsent','nameservers'=>'management_definitely_unsent','renew'=>'renewal_definitely_unsent'] as $action=>$code){
    $retained=new Operation(['domain_id'=>$namesilo->id,'service_id'=>$namesilo->service_id,'server_id'=>$namesilo->server_id,'registrar_key'=>'namesilo',
        'account_fingerprint'=>$namesilo->account_fingerprint,'authority_generation'=>$namesilo->authority_generation,'operation_key'=>'staff-unsent-'.$action,
        'action'=>$action,'funding_basis'=>$action==='nameservers'?'non_billable_management':'paid_invoice','currency'=>'USD','amount'=>'0.00','state'=>'held',
        'safe_code'=>$code,'attempt'=>['seal'=>'sealed','execution'=>null,'send_state'=>'possibly_sent']]);
    $id=\Illuminate\Support\Facades\DB::table('domains_operations')->insertGetId($retained->getAttributes());
    $current=Operation::findOrFail($id);
    $words=Labels::reason($code);$unknown=\Paymenter\Extensions\Servers\Domains\Admin\Support\PaidUncertainty::title($current);
    same(null,RequestFacts::for($current)['outcome'],'Established unsent '.$action.' evidence is not an unknown outcome');
    Livewire\Livewire::actingAs($actor)->test(ViewOperation::class,['record'=>$id])->assertSee($words)->assertDontSee($unknown);
    $current->safe_code='reconciliation_required';
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$id)->update(['safe_code'=>$current->safe_code]);
    same($unknown,RequestFacts::for($current)['outcome'],'A sealed '.$action.' attempt without unsent proof remains unknown');
    Livewire\Livewire::actingAs($actor)->test(ViewOperation::class,['record'=>$id])->assertSee($unknown);
}
$nativeSuccessful=true;echo "Staff nameserver refusal label gives one consistent next step.\n";
