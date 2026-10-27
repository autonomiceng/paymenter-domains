<?php
// Adapted from Enom 444f31c TransferOrders; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Dormant owner POST boundary. Installed CSRF/form keys/rate limits and trim exclusion belong to33/39. */
final class TransferInputs
{
    private static bool $writing = false;
    private const OPEN = ['awaiting_payment', 'awaiting_code', 'queued', 'held'];
    public function __construct(private readonly Clock $clock, private readonly Refunds $refunds) {}
    public static function validCode(#[\SensitiveParameter] mixed $code): bool
    {
        return is_string($code) && strlen($code) >= 1 && strlen($code) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $code);
    }
    public static function deadline(Operation $op, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $v = $op->policy['paid_at'] ?? null;
        if (!is_string($v) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', $v)) { return null; }
        try { $at = new \DateTimeImmutable($v); } catch (\Throwable) { return null; }
        return $at->format(DATE_ATOM) === $v && $at <= $now && $at >= $op->created_at ? $at->modify('+30 days') : null;
    }
    public static function requireEditable(Operation $op, \DateTimeImmutable $now): void
    {
        if (array_key_exists('paid_at', $op->policy ?? []) && (!($d = self::deadline($op, $now)) || $now >= $d)) {
            throw new \DomainException('Transfer input cutoff reached.');
        }
    }
    /** Code entry and setup keep their cutoff; only frozen-code contact correction can outlive it. */
    public static function contactsEditable(Operation $op, \DateTimeImmutable $now): bool
    {
        $deadline = self::deadline($op, $now);
        if (!$deadline) { return false; }
        if ($now < $deadline) { return true; }
        $saved = $op->policy['code_saved_at'] ?? null;
        if (!self::validCode($op->payload['epp'] ?? null) || !is_string($saved)
            || !preg_match('/^\\d{4}-\\d\\d-\\d\\dT\\d\\d:\\d\\d:\\d\\d\\+00:00$/D', $saved)) { return false; }
        try { $at = new \DateTimeImmutable($saved); } catch (\Throwable) { return false; }
        return $at->format(DATE_ATOM) === $saved && $at >= $op->created_at && $at < $deadline;
    }
    public static function guard(#[\SensitiveParameter] Operation $op): void
    {
        if ($op->action !== 'transfer') { return; }
        $before = $op->getOriginal('policy') ?? []; $after = $op->policy ?? [];
        if ((array_key_exists('paid_at', $before) && ($after['paid_at'] ?? null) !== $before['paid_at'])
            || (!self::$writing && (($after['code_saved_at'] ?? null) !== ($before['code_saved_at'] ?? null)
                || array_key_exists('code_saved_at', $after) !== array_key_exists('code_saved_at', $before)
                || array_key_exists('paid_at', $after) !== array_key_exists('paid_at', $before)
                || ($op->payload['epp'] ?? null) !== ($op->getOriginal('payload')['epp'] ?? null)))) {
            throw new \DomainException('Transfer inputs require the owner input authority.');
        }
    }
    private static function write(\Closure $call): mixed
    {
        $old = self::$writing; self::$writing = true;
        try { return $call(); } finally { self::$writing = $old; }
    }
    private static function open(Operation $op, array $l): void
    {
        if ($op->action !== 'transfer' || $op->term !== 1 || !in_array($op->state, self::OPEN, true)
            || $op->completed_at !== null || $op->started_at !== null || $op->request_fingerprint !== null
            || $l['domain']->status !== 'transfer_pending' || !in_array($l['service']->status, ['pending', 'active'], true)
            || $l['cancel']->contains('service_id', $l['service']->id) || array_intersect(['claim', 'seal', 'refund', 'refund_authorization'], array_keys($op->funding ?? []))
            || $l['operations']->contains(static fn ($peer) => $peer->id !== $op->id && !OperationTerminalProof::safe($peer, $l['domain']))) {
            throw new \DomainException('Transfer inputs unavailable.');
        }
        RefundProof::requireUnsent($op, $l['domain']); RefundPayment::requireLine($op, $l);
    }
    private function payment(Operation $op, array $l): void
    {
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id);
        if ($invoice->status === 'pending') {
            if (array_key_exists('paid_at', $op->policy ?? [])) { throw new \DomainException('Transfer payment changed.'); }
            return;
        }
        RefundPayment::requireCurrent($op, $l);
        if (!array_key_exists('paid_at', $op->policy ?? [])) {
            $at = TransferPayments::recover($op, $l, $this->clock->now());
            if ($at === null) { throw new \DomainException('Transfer payment time is unverified.'); }
            self::write(fn () => $op->update(['policy' => [...($op->policy ?? []), 'paid_at' => $at]]));
        }
        if (!self::deadline($op, $this->clock->now())) { throw new \DomainException('Transfer payment time is unverified.'); }
    }
    public function paymentAccepted(int $id): void
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        DB::transaction(function () use ($seed, $id): void {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            self::open($op, $l); $this->payment($op, $l);
            if (isset($op->policy['paid_at']) && !self::validCode($op->payload['epp'] ?? null) && $op->state !== 'held') {
                $op->update(['state' => 'awaiting_code', 'safe_code' => 'transfer_code_required']);
            }
        });
    }
    /** Reads only the POST body; returns no model, code or request content. No exception retains its cause. */
    public function submitCode(#[\SensitiveParameter] Request $request, int $id): void
    {
        try {
            OperationClaims::outside();
            if (!$request->isMethod('POST') || !$request->user() || $request->query->has('transfer_code')) { throw new \DomainException; }
            $code = $request->request->get('transfer_code');
            if (!self::validCode($code)) { throw new \DomainException; }
            $seed = Operation::findOrFail($id)->domain()->firstOrFail();
            DB::transaction(function () use ($request, $id, $seed, $code): void {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                if ((int) $l['user']->id !== (int) $request->user()->id) { throw new \DomainException; }
                self::open($op, $l); $this->payment($op, $l); self::requireEditable($op, $this->clock->now());
                if ($op->state === 'held' && !in_array($op->safe_code, ['contact_details_required', 'transfer_setup_review_required'], true)) { throw new \DomainException; }
                self::write(fn () => $op->update(['payload' => [...($op->payload ?? []), 'epp' => $code],
                    'policy' => [...($op->policy ?? []), 'code_saved_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)]]));
            });
        } catch (\Throwable) { throw new \DomainException('Transfer code could not be saved.'); }
    }
    /** Explicit single-operation timeout, no scheduler. Disposition persists if the credit step fails. */
    public function expireMissingCode(int $id): ?array
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $rejected = DB::transaction(function () use ($seed, $id): bool {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            if ($op->state === 'rejected') { return $op->safe_code === 'transfer_code_timeout'; }
            self::open($op, $l); $this->payment($op, $l);
            $deadline = self::deadline($op, $this->clock->now());
            if (!$deadline || $this->clock->now() < $deadline || array_key_exists('epp', $op->payload ?? []) && $op->payload['epp'] !== null) { return false; }
            $attempt = ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent',
                'transfer_timeout' => ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation, 'deadline' => $deadline->format(DATE_ATOM)]];
            ContactSetup::recoveryWrite(fn () => $op->update(['state' => 'rejected', 'safe_code' => 'transfer_code_timeout', 'attempt' => $attempt, 'completed_at' => $this->clock->now()]));
            AcquisitionRetirement::retainClosure($op,$l['transactions']->where('invoice_id',$op->invoice_id),
                $l['invoices']->firstWhere('id',$op->invoice_id)?->status,$this->clock->now(),cancelled:true);
            $l['domain']->update(['status' => 'transfer_rejected']);
            NativeDomainGuard::terminal($l, $op);
            return true;
        });
        return $rejected ? $this->refunds->creditRejected($id) : null;
    }
}
