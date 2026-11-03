<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Setting;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Contracts\Cache\LockTimeoutException;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, UpgradeGate, OfferScheduler, Capabilities};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime, CatalogueAcceptances};

final class InitializationInterrupted extends RuntimeException {}
$entry = new Domains; $commit = false; $competing = false;
$interrupts = ['create index "domains_domains_user_id_index"', 'create table "domains_operations"',
    'create table "domains_observation_queues"', 'create table "domains_reminder_deliveries"',
    'create table "domains_psl_status"', 'create table "domains_watch_audit"',
    'create table "domains_watch_names"', 'create table "domains_watch_observations"'];
DB::connection()->beforeExecuting(static function ($sql) use (&$interrupts, &$competing, $entry): void {
    if (!$interrupts || !str_starts_with($sql, $interrupts[0])) { return; }
    array_shift($interrupts);
    if (!$competing) { try { $entry->installed(); } catch (LockTimeoutException|DomainException) { $competing = true; } }
    throw new InitializationInterrupted('After first complete table, before the next DDL');
});
try { $entry->installed(); throw new LogicException('DDL interruption did not execute'); }
catch (InitializationInterrupted) {}
expect($competing, 'Competing initializers cannot acquire installation authority');
$pending = static function (): void {
    foreach ([Runtime::observations(...), OfferScheduler::routed(...), Capabilities::routed(...),
        static fn () => CallerRouting::legacy(static fn () => true), UpgradeGate::legacy(...)] as $call) {
        $refused = false;
        try { $call(); } catch (DomainException) { $refused = true; }
        expect($refused, 'Incomplete durable intent refuses executable routing and legacy work');
    }
};
$pending();
$intent = Setting::where('key',UpgradeGate::KEY)->sole()->value;
$changed = json_decode($intent, true); $changed['generation']++;
$row = Setting::where('key',UpgradeGate::KEY)->sole(); $row->value = json_encode($changed); $row->save();
$refused = false;
try { $entry->installed(); } catch (DomainException) { $refused = true; } catch (InitializationInterrupted) {}
expect($refused, 'Changed intent generation refuses before schema work');
$row = Setting::where('key',UpgradeGate::KEY)->sole(); $row->value = $intent; $row->save();
// An owned intent still cannot initialize over unexpected holding/credential evidence.
DB::table('domains_domains')->insert(['service_id'=>91,'user_id'=>92,'server_id'=>93,'registrar_key'=>'enom',
    'account_fingerprint'=>str_repeat('a',64),'domain'=>'retained-placeholder.com','sld'=>'retained-placeholder','tld'=>'com','status'=>'active']);
$held = DB::table('domains_domains')->get()->toJson();
try { $entry->installed(); throw new LogicException('Retained holding unexpectedly activated'); }
catch (DomainException) {}
same($held, DB::table('domains_domains')->get()->toJson(), 'Refusal preserves unexpected holding evidence');
DB::table('domains_domains')->delete();
while ($interrupts) {
    try { $entry->installed(); throw new LogicException('DDL interruption did not execute'); }
    catch (InitializationInterrupted) {}
    $pending();
    same($intent, Setting::where('key',UpgradeGate::KEY)->sole()->value, 'Every partial schema resumes only the original initialization intent');
}
Setting::saving(static function (Setting $row) use (&$commit): void {
    if (!$commit || $row->key !== UpgradeGate::KEY) { return; }
    $commit = false; throw new InitializationInterrupted('Before active-state commit');
});
$commit = true;
try { $entry->installed(); throw new LogicException('Final commit interruption did not execute'); }
catch (InitializationInterrupted) {}
$pending();
same($intent, Setting::where('key',UpgradeGate::KEY)->sole()->value, 'DDL and failed activation retain the same durable intent');
$entry->installed();
same('active', UpgradeGate::routeState()['state'], 'Retry resumes the proven partial install and commits checked activation');
same(2, UpgradeGate::generation(), 'Interrupted retries grant one activation generation');
$entry->installed(); same(2, UpgradeGate::generation(), 'Completed retry is idempotent');
$nativeSuccessful = true;
