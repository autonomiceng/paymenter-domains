<?php
// Adapted from Enom 444f31c RegistrationSetup recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Product, Server, Service, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};

/** Local contact-only recovery. Never sends, refunds, releases a name or reinterprets a provider result. */
final class ContactRecovery
{
    /** Payment/engine hook only; failed validation preserves the exact encrypted desired state. */
    public static function hold(int $operationId): Operation
    {
        $hint = Operation::findOrFail($operationId); $ownerId = Domain::findOrFail($hint->domain_id)->user_id;
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Contact hold owns its transaction.'); }
        return DB::transaction(static function () use ($hint, $ownerId): Operation {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $funding = InvoiceFunding::lock($hint->invoice_id);
            [$domain, $operations] = self::lock($hint); $op = $operations->firstWhere('id', $hint->id);
            self::paidIdentity($funding, $op, $domain); self::unsent($op);
            if (!in_array($op->action, ['register', 'transfer'], true) || !in_array($op->state, ['awaiting_payment', 'queued', 'held'], true)
                || ($op->state === 'held' && !in_array($op->safe_code, ['contact_details_required', 'registry_details_unsupported'], true))
                || $domain->status !== ($op->action === 'register' ? 'registration_pending' : 'transfer_pending')
                || $domain->provider_order_id !== null || $domain->provider_domain_id !== null || $domain->provider_expires_at !== null) {
                throw new \DomainException('Contact hold unavailable.');
            }
            try {
                ContactSetup::validate($op);
                if (($op->payload['setup_required'] ?? null) === false && ($op->payload['registry_supported'] ?? null) === true) { return $op; }
                $code = 'contact_details_required';
            }
            catch (ContactValidation) { $code = 'contact_details_required'; }
            catch (\DomainException) { $code = 'registry_details_unsupported'; }
            if ($op->state !== 'held' || $op->safe_code !== $code) { ContactSetup::recoveryWrite(static fn () => $op->update(['state' => 'held', 'safe_code' => $code])); }
            return $op;
        });
    }

    public static function replace(User $actor, int $operationId, string $reason): Operation
    {
        self::reason($reason);
        $hint = Operation::findOrFail($operationId); $ownerId = Domain::findOrFail($hint->domain_id)->user_id;
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Contact recovery owns its transaction.'); }
        return DB::transaction(static function () use ($actor, $hint, $ownerId, $reason): Operation {
            $users = User::whereKey([$actor->id, $ownerId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->id); $owner = $users->get($ownerId);
            if (!$currentActor || !$owner || ((int) $actor->id !== (int) $ownerId && !$currentActor->hasPermission('admin.domains.manage'))) {
                throw new \DomainException('Contact recovery unavailable.');
            }
            $funding = InvoiceFunding::lock($hint->invoice_id);
            [$domain, $operations] = self::lock($hint);
            $op = $operations->firstWhere('id', $hint->id);
            self::paidIdentity($funding, $op, $domain); self::unsent($op);
            if (!in_array($op->action, ['register', 'transfer'], true) || $op->state !== 'held' || $op->safe_code !== 'contact_details_required'
                || (int) $domain->user_id !== (int) $ownerId
                || $domain->status !== ($op->action === 'register' ? 'registration_pending' : 'transfer_pending')
                || $domain->provider_order_id !== null || $domain->provider_domain_id !== null || $domain->provider_expires_at !== null
                || $operations->contains(static fn ($peer) => $peer->id !== $op->id && !in_array($peer->state, ['rejected', 'disposed'], true))) {
                throw new \DomainException('Contact recovery unavailable.');
            }
            $payload = $op->payload ?? []; $policy = $op->policy ?? [];
            $history = $policy['contact_readmissions'] ?? [];
            if (!is_array($history) || !array_is_list($history) || count($history) >= 10) { throw new \DomainException('Contact recovery limit reached.'); }
            $contacts = ContactDetails::validate($op->registrar_key, ContactDetails::collect($owner));
            RegistryInputs::validate($op->registrar_key, $domain->tld, $payload['registry_schema'] ?? null, $payload['registry'] ?? null);
            $payload['contacts'] = $contacts; $payload['setup_required'] = false; $payload['setup_fields'] = []; $payload['registry_supported'] = true;
            $history[] = ['actor_id' => (int) $actor->id, 'owner_id' => (int) $ownerId, 'reason' => $reason,
                'at' => now()->toIso8601String(), 'previous_code' => $op->safe_code];
            $policy['contact_readmissions'] = $history;
            // Transfer cutoff/code admission remains 27. No new job or attempt is created here.
            ContactSetup::recoveryWrite(static fn () => $op->update(['payload' => $payload, 'policy' => $policy,
                'state' => $op->action === 'register' ? 'queued' : 'held',
                'safe_code' => $op->action === 'register' ? null : 'transfer_setup_review_required']));
            return $op;
        });
    }

    /** Contacts are non-billable. A proven-unsent management request can close with an audit, never credit. */
    public static function closeUnsent(User $staff, int $operationId, string $reason): Operation
    {
        self::reason($reason); $hint = Operation::findOrFail($operationId);
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Contact disposition owns its transaction.'); }
        return DB::transaction(static function () use ($staff, $hint, $reason): Operation {
            $actor = User::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            if (!$actor->hasPermission('admin.domains.manage')) { throw new \DomainException('Contact disposition unavailable.'); }
            // Native prefix precedes account/domain/operation locks. No invoice exists for this action.
            $service = Service::whereKey($hint->service_id)->lockForUpdate()->firstOrFail();
            $product = $service->product()->lockForUpdate()->firstOrFail();
            [$domain, $operations] = self::lock($hint); $op = $operations->firstWhere('id', $hint->id);
            self::identity($op, $domain, $service, $product); self::unsent($op);
            if ($op->action !== 'contacts' || $op->state !== 'held' || $op->funding_basis !== 'non_billable_management'
                || $op->invoice_id !== null || $op->invoice_item_id !== null || PricingRule::minor($op->amount) !== 0
                || (int) $op->term !== 1 || ($op->funding ?? []) !== []) { throw new \DomainException('Contact disposition unavailable.'); }
            $policy = $op->policy ?? [];
            if (array_key_exists('contact_disposition', $policy)) { throw new \DomainException('Contact disposition already recorded.'); }
            $policy['contact_disposition'] = ['actor_id' => (int) $actor->id, 'reason' => $reason,
                'at' => now()->toIso8601String(), 'outcome' => 'definitely_unsent', 'financial' => 'non_refundable'];
            $attempt = ['disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent', 'send_state' => 'definitely_unsent', 'seal' => 'absent'];
            ContactSetup::recoveryWrite(static fn () => $op->update(['state' => 'rejected', 'safe_code' => 'contacts_closed_unsent', 'policy' => $policy,
                'attempt' => $attempt, 'completed_at' => now()]));
            return $op;
        });
    }

    private static function lock(Operation $hint): array
    {
        $server = Server::whereKey($hint->server_id)->lockForUpdate()->firstOrFail();
        $account = Account::where('server_id', $server->id)->lockForUpdate()->firstOrFail();
        if ($server->extension !== 'Domains' || $hint->account_fingerprint !== $account->account_fingerprint
            || $hint->registrar_key !== $account->registrar_key) { throw new \DomainException('Contact account changed.'); }
        $domain = Domain::whereKey($hint->domain_id)->lockForUpdate()->firstOrFail();
        $operations = $domain->operations()->orderBy('id')->lockForUpdate()->get();
        foreach ($operations as $op) { $op->setRelation('domain', $domain); }
        return [$domain, $operations];
    }

    private static function paidIdentity(InvoiceFunding $funding, Operation $op, Domain $domain): void
    {
        $funding->requirePaid($op, $domain);
        // requirePaid proves these exact rows are ALREADY held by the full native prefix.
        // Repeat their current locking reads; an earlier permission read may have opened an old read view.
        $service = Service::whereKey($op->service_id)->lockForUpdate()->firstOrFail();
        self::identity($op, $domain, $service, $service->product()->lockForUpdate()->firstOrFail());
    }

    private static function identity(Operation $op, Domain $domain, Service $service, Product $product): void
    {
        if ($op->authority_generation < 1 || $op->authority_generation !== $domain->authority_generation
            || $op->domain_id !== $domain->id || $op->service_id !== $domain->service_id || (int) $service->id !== (int) $op->service_id
            || (int) $product->id !== (int) $service->product_id || $op->server_id !== $domain->server_id
            || $op->registrar_key !== $domain->registrar_key || $op->account_fingerprint !== $domain->account_fingerprint
            || $domain->reservation_name !== $domain->domain || (int) $service->user_id !== (int) $domain->user_id
            || (int) $product->server_id !== (int) $domain->server_id || !$domain->billing_managed
            || $service->currency_code !== $op->currency || !in_array($service->status, [Service::STATUS_PENDING, Service::STATUS_ACTIVE], true)) {
            throw new \DomainException('Contact ownership changed.');
        }
    }

    private static function unsent(Operation $op): void
    {
        $attempt = $op->attempt ?? [];
        if ($op->completed_at !== null || $op->started_at !== null || $op->lease_expires_at !== null || $op->request_fingerprint !== null
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null
            || array_key_exists('execution', $op->cost ?? []) || array_key_exists('actual', $op->cost ?? [])
            || array_key_exists('dispatch_attempted_at', $op->policy ?? [])
            || array_intersect(['refund', 'claim', 'seal', 'grant'], array_keys($op->funding ?? []))
            || array_diff(array_keys($attempt), ['seal', 'send_state', 'disposition', 'rejection_basis'])
            || ($attempt !== [] && (($attempt['seal'] ?? null) !== 'absent' || ($attempt['send_state'] ?? null) !== 'definitely_unsent'))
            || (array_key_exists('disposition', $attempt) && $attempt['disposition'] !== 'rejected')
            || (array_key_exists('rejection_basis', $attempt) && $attempt['rejection_basis'] !== 'definitely_unsent')) {
            throw new \DomainException('This request is not proven unsent.');
        }
    }

    private static function reason(string $reason): void
    {
        if ($reason !== trim($reason) || strlen($reason) < 5 || strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new \DomainException('Enter a review reason between 5 and 500 bytes.');
        }
    }
}
