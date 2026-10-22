<?php
// Synthetic slice18/19 integration; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/operation-fixture.php';
use App\Models\Role;
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, ContactSetup};

// Privileged fixture injection simulates retained/corrupt evidence, never a runtime editing API.
$inject = static function (Operation $op, array $changes): void {
    foreach (['payload', 'attempt', 'policy', 'funding'] as $key) {
        if (array_key_exists($key, $changes)) { $changes[$key] = Crypt::encryptString(json_encode($changes[$key])); }
    }
    DB::table('domains_operations')->where('id', $op->id)->update($changes);
};
$holdLevels = [];
Operation::updating(static function ($op) use (&$holdLevels): void {
    if ($op->isDirty('safe_code') && $op->safe_code === 'contact_details_required') { $holdLevels[] = DB::transactionLevel(); }
});
foreach (['flags', 'registry', 'contacts', 'schema'] as $bad) {
    $op = $new(placeholderComFixtureName('unready-'.$bad.'-placeholder.com')); $payload = $op->payload;
    match ($bad) {
        'flags' => $payload['setup_required'] = true,
        'registry' => $payload['registry_supported'] = false,
        'contacts' => $payload['contacts']['registrant']['email_address'] = 'invalid',
        'schema' => $payload['registry_schema'] = [['name' => 'unsupported']],
    };
    $inject($op, ['payload' => $payload]); $before = $preflight->calls;
    $held = $assertNoSend($op);
    same($bad === 'schema' ? 'registry_details_unsupported' : 'contact_details_required', $held->safe_code, 'Contacts gate holds through recovery authority');
    same($before, $preflight->calls, 'Refused contacts never reach provider preflight');
    same([], $held->attempt ?? [], 'Refused contacts acquire no claim or seal');
    same(null, $held->request_fingerprint, 'Refused contacts acquire no request digest');
    same(null, $held->started_at, 'Refused contacts never start');
    same(null, $held->lease_expires_at, 'Refused contacts acquire no lease');
    expect(!isset($held->funding['claim'], $held->funding['seal']), 'No finance grant evidence');
    same($payload, $held->payload, 'Refusal preserves frozen contact payload');
    if ($bad !== 'schema') {
        commerceReject(fn () => $held->fresh()->update(['state' => 'queued', 'safe_code' => null]));
        $recovered = ContactRecovery::replace($user, $held->id, 'Owner revalidated contact details');
        same('queued', $recovered->state, 'Only audited recovery releases contact hold');
        $audit = $recovered->policy['contact_readmissions'];
        $recovered->update(['policy' => $recovered->policy + ['finance_note' => 'preserved']]);
        same($audit, $recovered->fresh()->policy['contact_readmissions'], 'Finance policy merge retains contact audit');
        // Both independent model callbacks must fire: contacts then immutable finance evidence.
        commerceReject(fn () => $recovered->fresh()->update(['payload' => array_replace($recovered->payload, ['contacts' => []])]));
        $result = $engine->execute($recovered->id);
        same($audit, $result->policy['contact_readmissions'], 'Claim/seal/finish retain contact audit');
        commerceReject(fn () => $result->fresh()->update(['attempt' => []]));
        foreach ([[], ['contact_readmissions' => []], ['contact_readmissions' => [['reason' => 'rewritten']]],
            ['contact_readmissions' => array_fill(0, 11, [])]] as $change) {
            commerceReject(fn () => $result->fresh()->update(['policy' => array_replace(array_diff_key($result->policy, ['contact_readmissions' => true]), $change)]));
        }
    }
}
expect($holdLevels !== [] && array_unique($holdLevels) === [1], 'Contact hold owns a fresh transaction after refused claim commits');
// Valid frozen contacts are revalidated at final seal even after a privileged out-of-band change.
$op = $new(placeholderComFixtureName('contacts-changed-before-seal-placeholder.com'));
$preflight->during = static function ($claimed) use ($inject): void {
    $payload = $claimed->payload; $payload['contacts']['tech']['phone'] = 'invalid';
    $inject($claimed, ['payload' => $payload]);
};
$result = $assertNoSend($op); $preflight->during = null;
same('seal_authority_invalid', $result->safe_code, 'Invalid postclaim contacts refuse final seal');
same('absent', $result->attempt['seal'], 'Refused final seal never opens a grant');
// Real claimed and sealed rows cannot be readmitted even if somebody relabels them contact-held.
foreach ([false, true] as $seal) {
    $op = $new($seal ? 'contacts-sealed-recovery-placeholder.com' : 'contacts-claimed-recovery-placeholder.com');
    [$claimed, $domain, $binding] = $claims->claim($op->id);
    if ($seal) {
        $context = new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
            Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+300 seconds'),
            new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(10), $clock);
        $claims->seal($claimed, $preflight->inspect($claimed, $domain, $context), $clock->now());
    }
    $inject($op, ['state' => 'held', 'safe_code' => 'contact_details_required']);
    commerceReject(fn () => ContactRecovery::hold($op->id));
    commerceReject(fn () => ContactRecovery::replace($user, $op->id, 'Cannot erase a finance claim'));
}
$role = Role::create(['name' => 'Synthetic contact manager', 'permissions' => ['admin.domains.manage']]);
$staff = commerceUser(); $staff->update(['role_id' => $role->id]);
$base = $new(placeholderComFixtureName('contact-evidence-matrix-placeholder.com'));
$manageBase = $new(placeholderComFixtureName('contact-close-evidence-matrix-placeholder.com'));
$management = Operation::create(['domain_id' => $manageBase->domain_id, 'service_id' => $manageBase->service_id, 'server_id' => $manageBase->server_id,
    'registrar_key' => $manageBase->registrar_key, 'account_fingerprint' => $manageBase->account_fingerprint, 'authority_generation' => 1,
    'operation_key' => 'contact-finance-close', 'action' => 'contacts', 'term' => 1, 'currency' => 'USD', 'amount' => '0.00',
    'state' => 'held', 'safe_code' => 'contact_details_required', 'funding_basis' => 'non_billable_management']);
$inject($base, ['state' => 'held', 'safe_code' => 'contact_details_required']);
$unsentRefusal = static function (callable $call): void {
    try { $call(); expect(false, 'Expected finance evidence refusal'); }
    catch (\DomainException $error) { same('This request is not proven unsent.', $error->getMessage(), 'Finance evidence is the actual refusal reason'); }
};
foreach ([['attempt' => ['claim_key' => null]], ['attempt' => ['seal' => 'sealed']], ['attempt' => ['execution' => null]],
    ['attempt' => ['authorized_cost' => null]], ['attempt' => ['grant' => null]], ['funding' => ['claim' => null]],
    ['funding' => ['seal' => null]], ['funding' => ['grant' => null]], ['request_fingerprint' => 'digest'],
    ['started_at' => '2026-10-18 12:00:00'], ['lease_expires_at' => '2026-10-18 12:06:00']] as $evidence) {
    foreach ([$base, $management] as $target) {
        $raw = $target->fresh()->getAttributes();
        // Prove no independent peer/state/funding blocker before EACH injected evidence case.
        if ($target->id === $base->id) {
            same('queued', ContactRecovery::replace($user, $target->id, 'Evidence-free recovery control')->state, 'Evidence-free acquisition recovers');
        } else {
            same('rejected', ContactRecovery::closeUnsent($staff, $target->id, 'Evidence-free closure control')->state, 'Evidence-free contact change closes');
        }
        DB::table('domains_operations')->where('id', $target->id)->update($raw);
        $inject($target, $evidence);
        if ($target->id === $base->id) {
            $unsentRefusal(fn () => ContactRecovery::hold($target->id));
            $unsentRefusal(fn () => ContactRecovery::replace($user, $target->id, 'Cannot readmit finance evidence'));
        } else { $unsentRefusal(fn () => ContactRecovery::closeUnsent($staff, $target->id, 'Cannot close finance evidence')); }
        DB::table('domains_operations')->where('id', $target->id)->update($raw);
    }
}
$closed = ContactRecovery::closeUnsent($staff, $management->id, 'Close the proven unsent contact change');
$audit = $closed->policy['contact_disposition'];
$closed->update(['policy' => $closed->policy + ['finance_note' => 'retained']]);
same($audit, $closed->fresh()->policy['contact_disposition'], 'Policy merge retains disposition');
foreach ([[], ['contact_disposition' => null], ['contact_disposition' => ['reason' => 'rewritten']]] as $change) {
    commerceReject(fn () => $closed->fresh()->update(['policy' => $change]));
}
same(0, DB::transactionLevel(), 'Integration leaves no transaction');
echo "Contact readiness, finance refusal matrix, independent guards and policy audit integration passed\n";
$nativeSuccessful = true;
