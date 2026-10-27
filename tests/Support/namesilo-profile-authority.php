<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Exact authority diagnostics, with offline failures.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
$probeOp = $nsNew(placeholderComFixtureName('profile-diagnostic-errors-placeholder.com')); [$claimed] = $claims->claim($probeOp->id);
$tables = ['domains_operations', 'domains_domains', 'domains_accounts', 'invoices']; $rows = [];
foreach ($tables as $table) { $rows[$table] = Illuminate\Support\Facades\DB::table($table)->get()->map(fn ($row) => (array) $row)->all(); }
foreach (['claim', 'evidence', 'binding', 'contacts', 'acquisition', 'funding', 'malformed-binding', 'infrastructure'] as $case) {
    $snapshot = clone $claimed; $portBinding = $binding; $diagnostics = []; $calls = 0; $probeClaims = $claims;
    $db = static fn ($table, $id, $change) => Illuminate\Support\Facades\DB::table($table)->where('id', $id)->update($change);
    if ($case === 'claim') { $snapshot->state = 'held'; }
    if ($case === 'evidence') { $snapshot->policy = $snapshot->policy + ['changed' => true]; }
    if ($case === 'binding') { $portBinding = new Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo', $binding->serverId, $binding->environment, 'other-account'); }
    if ($case === 'contacts') { $db('domains_operations', $claimed->id, ['payload' => Illuminate\Support\Facades\Crypt::encryptString(json_encode(array_replace($claimed->payload, ['setup_required' => true])))]); }
    if ($case === 'acquisition') { $db('domains_domains', $claimed->domain_id, ['status' => 'active']); }
    if ($case === 'funding') { $db('invoices', $claimed->invoice_id, ['status' => 'pending']); }
    if ($case === 'malformed-binding') {
        Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['stable_identity' => Illuminate\Support\Facades\Crypt::encryptString('')]);
    }
    if ($case === 'infrastructure') {
        $brokenClock = new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
            public function now(): DateTimeImmutable { throw new LogicException('Synthetic infrastructure failure'); }
        };
        $probeClaims = new Paymenter\Extensions\Servers\Domains\Services\OperationClaims($bindings, $brokenClock);
    }
    $probe = new Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles($portBinding, $probeClaims, $clock,
        static function () use (&$calls): never { $calls++; throw new LogicException('Unexpected provider call'); },
        static function ($predicate, $passed) use (&$diagnostics): void { $diagnostics[$predicate] = $passed; });
    $ctx = new RequestContext($portBinding, RequestOrigin::Background, $clock->now()->modify('+25 seconds'), new RequestBudget(3), $clock);
    $caught = null;
    try { $probe->prepare($snapshot, $ctx); } catch (Throwable $error) { $caught = $error; }
    expect($caught !== null, 'Authority probe refuses: '.$case);
    same(in_array($case, ['claim', 'evidence', 'binding'], true) ? ['authority_before' => false] : [], $diagnostics,
        'Only explicit authority mismatch emits false: '.$case);
    same(0, $calls, 'Authority refusal sends no provider command: '.$case);
    if ($case === 'infrastructure') { same(LogicException::class, $caught::class, 'Infrastructure error is rethrown unchanged'); }
    foreach ($rows as $table => $records) { foreach ($records as $record) { $id = $record['id']; unset($record['id']); $db($table, $id, $record); } }
}
$claims->finish($claimed);
