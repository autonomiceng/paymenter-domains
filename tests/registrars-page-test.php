<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts;
use Paymenter\Extensions\Servers\Domains\Services\BalanceSchema;

$registrarsPart='list';require __DIR__.'/Support/registrar-pages.php';
$nativeSuccessful=false;
// A worker using old credentials and a failed balance check are separate problems staff must see.
$server=Server::findOrFail($enom->server_id);
DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>str_repeat('0',64),'finished_at'=>$clock->now()->getTimestamp()]);
DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$enom->account_fingerprint)->update(['last_error_code'=>'read_failed']);
expect($bindings->configurationAvailable((int)$server->id),'Saved credentials still match while the worker turn is stale');
$reason=RegistrarFacts::status($server,$enom)['reason'];$cause=RegistrarFacts::balance($enom)['cause'];
expect($reason!==null&&$cause!==null,'The stale turn and failed balance each have a cause');
$note=static function()use($staff,$server):string {
    $page=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSuccessful();
    return (string)$page->instance()->getTable()->getColumn('note')->record($server)->getState();
};
$shown=$note();
expect(str_contains($shown,$reason)&&str_contains($shown,$cause),'A failed balance check must not hide the stale worker configuration reason');
// When the saved settings themselves cannot load, the balance cause already explains that failure.
$server->settings()->where('key','domains_credentials')->delete();
expect(!$bindings->configurationAvailable((int)$server->id),'Missing saved credentials make the configuration unavailable');
$cause=RegistrarFacts::balance($enom)['cause'];$shown=$note();
expect($cause!==null&&str_contains($shown,$cause)&&!str_contains($shown,$reason),'A balance configuration failure keeps its recovery step without repeating the status reason');
$nativeSuccessful=true;
