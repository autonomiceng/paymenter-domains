<?php
// Claim and seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use App\Models\{Server, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Money, Query};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, ComplimentaryCosts, FreshTransaction, FundsWaits, InvoiceFunding, TransferInputs, WriteHeld, WriteMoney};

/** Ordered native and holding locks, followed by the current acquisition authority checks. */
final readonly class AcquisitionAuthority
{
    public const FUNDS_PROCEED = 'proceed';
    public const FUNDS_WAIT = 'wait';
    public const FUNDS_RETIRED_FOR_CREDIT = 'retired_for_credit';

    public function __construct(private AccountBindings $bindings, private Clock $clock, private ?Capabilities $offers = null) {}
    public function requireProcessing(AccountBinding $binding): void { $this->bindings->requireProcessing($binding); }

    public function requireProcessingLocked(Server $server, Account $account): void
    { $this->bindings->requireProcessingLocked($server, $account); }

    public function validate(Operation $op, Domain $domain, $all, InvoiceFunding $funding, Server $server, Account $account): array
    {
        $binding=self::requireAcquisitionAuthority($op,$domain,$all,$account,$this->clock->now());
        $paid=$funding->requireInitial($op,$domain);
        $this->bindings->requireProcessingLocked($server,$account);
        $op->setRelation('domain', $domain); // Contact validation consumes the already locked holding.
        return [$binding,$paid];
    }

    public static function requireAcquisitionAuthority(Operation $op,Domain $domain,$all,Account $account,\DateTimeImmutable $now): AccountBinding
    {
        $binding = new AccountBinding($account->registrar_key, (int) $account->server_id, Environment::from($account->environment), $account->stable_identity);
        if ($op->account_fingerprint !== $binding->fingerprint || $domain->account_fingerprint !== $binding->fingerprint
            || $op->registrar_key !== $binding->registrarKey || (int) $op->server_id !== $binding->serverId
            || $domain->registrar_key !== $binding->registrarKey || (int) $domain->server_id !== $binding->serverId
            || !in_array($op->action, ['register', 'transfer'], true) || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending') || !$domain->billing_managed
            || $domain->reservation_name !== $domain->domain || $domain->provider_domain_id !== null
            || $domain->provider_expires_at !== null || $domain->provider_order_id !== null || $op->provider_order_id !== null
            || $op->confirmed_expires_at !== null
            || $all->contains(static fn ($other) => $other->id !== $op->id && (!self::resolvedOther($other)
                || $other->account_fingerprint !== $op->account_fingerprint || $other->server_id !== $op->server_id
                || $other->authority_generation !== $op->authority_generation || $other->service_id !== $op->service_id))) {
            throw new \DomainException('Acquisition authority is unavailable.');
        }
        if ($op->action === 'transfer' && ($op->term !== 1 || !TransferInputs::validCode($op->payload['epp'] ?? null)
            || !TransferInputs::deadline($op, $now))) { throw new \DomainException('Transfer inputs incomplete.'); }
        return $binding;
    }

    private static function resolvedOther(Operation $op): bool
    {
        // A state label alone is never proof of a safe earlier outcome.
        return $op->state === 'rejected' && ($op->attempt['disposition'] ?? null) === 'rejected'
            && ($op->attempt['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($op->attempt['send_state'] ?? null) === 'definitely_unsent' && AcquisitionRetryProof::unsealed($op)
            && ($op->attempt['charge'] ?? null) === null && ($op->attempt['order_candidates'] ?? []) === [];
    }

    /** A fresh quote cannot expand the order's frozen spending authority. */
    public static function requirePurchasePrice(Operation $op, Money $cost): void
    {
        $saved = $op->cost ?? [];
        try {
            if ($op->currency !== 'USD' || ($saved['currency'] ?? null) !== 'USD' || ($saved['unit'] ?? null) !== 'term_total'
                || !is_string($saved['estimated'] ?? null)) { throw new \InvalidArgumentException; }
            $ceiling = new Money($saved['estimated'], 'USD');
            $total = new Money((string) $op->amount, 'USD');
        } catch (\InvalidArgumentException) {
            throw new WriteHeld('registrar_price_missing');
        }
        if (isset($op->policy['account_complimentary'])) {
            ComplimentaryCosts::require($op, $cost, $ceiling->amount);
            return;
        }
        if (WriteMoney::compare($cost->amount, $ceiling->amount) > 0 || WriteMoney::compare($cost->amount, $total->amount) > 0) {
            throw new WriteHeld('registrar_price_increased');
        }
    }

    /** Internal ordered authority scope shared by read-only reconciliation. */
    public function locked(int $id, \Closure $callback, ?int $staffId = null): mixed
    {
        self::outside();
        // Discovery is before the transaction, so it cannot establish an old repeatable-read view.
        $route = Operation::findOrFail($id); $holding = Domain::findOrFail($route->domain_id);
        return FreshTransaction::run(function () use ($route, $holding, $callback, $staffId) {
            $users=User::whereKey(array_filter([$holding->user_id,$staffId]))->orderBy('id')->lockForUpdate()->get();
            if(!$users->firstWhere('id',$holding->user_id)){throw new \DomainException('Missing domain owner.');}
            $staff=$users->firstWhere('id',$staffId); if($staff){$staff->setRelation('role',$staff->role()->lockForUpdate()->first());}
            $funding = InvoiceFunding::lock((int) $route->invoice_id);
            $server = Server::whereKey($route->server_id)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $route->server_id)->lockForUpdate()->firstOrFail();
            $catalogue = $this->offers ?? \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::afterPrefix(\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::class);
            // Lock credentials before domain/operation rows (same server mutex as rotation).
            $server->settings()->orderBy('id')->lockForUpdate()->get();
            $catalogueTld = $catalogue === null ? null
                : \Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $route->server_id)->where('tld', $holding->tld)->lockForUpdate()->first();
            $domain = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $domain->id)->orderBy('id')->lockForUpdate()->get();
            $funding->lockPayments();
            $op = $all->firstWhere('id', $route->id);
            if (!$op || $op->invoice_id !== $route->invoice_id || $op->server_id !== $route->server_id
                || $domain->user_id !== $holding->user_id || $op->domain_id !== $domain->id) {
                throw new \DomainException('Claim route changed.');
            }
            if ($domain->adoptionPending()) { throw new \DomainException('Registration verification required.'); }
            $op->catalogueTld = $catalogueTld;
            return $callback($op, $domain, $all, $funding, $server, $account, $staff);
        });
    }

    /** Use the locked result and the exact worker, when the caller may retire it.
     * @return self::FUNDS_PROCEED|self::FUNDS_WAIT|self::FUNDS_RETIRED_FOR_CREDIT
     */
    public static function fundsCutoff(Operation $op, AcquisitionContinuation $continuation, \DateTimeImmutable $now,
        ?AttemptFacts $worker = null): string
    {
        if (!FundsWaits::deadlinePassed($continuation->shortfallAt, $now)) { return self::FUNDS_PROCEED; }
        if ($worker === null || $worker->token->operationId !== (int) $op->id
            || !in_array($op->state, ['running', 'uncertain'], true) || $op->completed_at !== null
            || AcquisitionAttempt::conflicts($op) || !AcquisitionRetryProof::unsealed($op)
            || !OperationAttempts::unsealedUnsent($worker, true)
            || (!$worker->current && !OperationAttempts::releasedUnsent($worker, true))) {
            return self::FUNDS_WAIT;
        }
        return self::retirePreSeal($op, $worker, 'registrar_balance_insufficient', $now)
            ? self::FUNDS_RETIRED_FOR_CREDIT : self::FUNDS_WAIT;
    }

    /** Archive the unsealed attempt before a hold. The original clock remains available for the credit sweep. */
    public static function retirePreSeal(Operation $op, AttemptFacts $attempt, string $reason, \DateTimeImmutable $now): bool
    {
        if ($attempt->current && OperationWriter::at($now)->releasePreSeal($attempt->token) !== Transition::Applied) {
            return false;
        }
        $op->reloadAcquisitionAttempt();
        return $op->update(['state' => 'held', 'safe_code' => $reason, 'lease_expires_at' => null]);
    }

    public function expire(Operation $op, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= $this->clock->now();
        if ($op->lease_expires_at && $op->lease_expires_at > $now) { return false; }
        if (!AcquisitionRetryProof::expiredClaim($op, $now)) {
            $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
        }
        return true;
    }
    public static function payloadDigest(Operation $op): string { return hash('sha256', json_encode($op->payload, JSON_THROW_ON_ERROR)); }
    public static function query(Operation $op, Domain $domain): Query { return new Query($domain->domain, Action::from($op->action), (int) $op->term); }
    public static function digest(Operation $op, Domain $domain): string
    {
        return hash('sha256', json_encode([$op->operation_key, $domain->domain, $op->action, $op->term, $op->account_fingerprint,
            $op->authority_generation, self::payloadDigest($op)], JSON_THROW_ON_ERROR));
    }
    public static function outside(): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Operation work must start outside database transactions.'); }
    }
}
