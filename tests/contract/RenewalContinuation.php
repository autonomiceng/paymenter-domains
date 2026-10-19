<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;

/** One explicitly authorized OTE continuation, never inventory adoption or production replay policy. */
final class RenewalContinuation
{
    public static function claim(string $receipt, int $limit = 8): string
    {
        if (realpath($receipt) !== $receipt || basename($receipt) !== 'receipt.json'
            || (fileperms(dirname($receipt)) & 077) !== 0) { throw new \RuntimeException('Private prior receipt required.'); }
        $prior = PurchaseAttempt::readPrivate($receipt);
        $directory = dirname($receipt);
        $attempt = PurchaseAttempt::readPrivate($directory.'/attempt-private.json');
        $registration = PurchaseAttempt::readPrivate($directory.'/registration-attempt-private.json');
        $requests = $prior['requests'] ?? [];
        $registrations = array_values(array_filter($requests, static fn ($r) => ($r['command'] ?? null) === 'registerDomain'));
        $renewals = array_values(array_filter($requests, static fn ($r) => ($r['command'] ?? null) === 'renewDomain'));
        $count = $prior['request_count'] ?? null;
        if (!is_int($count) || $count !== count($requests) || $count < 1 || $limit < 8 || $count + $limit > 60) {
            throw new \RuntimeException('Cumulative OTE budget refused.');
        }
        $name = $attempt['name'] ?? '';
        if (($prior['renewal_reconciliation']['category'] ?? null) !== 'not_renewed'
            || ($attempt['renewal_category'] ?? null) !== 'not_renewed'
            || !in_array($prior['checks']['registration'] ?? null, ['accepted_via_real_admitted_protocol', 'reconciled_in_account'], true)
            || count($registrations) !== 1 || count($renewals) !== 1 || ($renewals[0]['route'] ?? null) !== 'apibatch'
            || ($attempt['command'] ?? null) !== 'renewDomain' || ($attempt['route'] ?? null) !== '/apibatch'
            || ($attempt['term'] ?? null) !== 1 || Shapes::strictDate($attempt['before_expiry'] ?? null) === null
            || !preg_match('/^contract-ote-[0-9a-f]{12}\.com$/D', $name)
            || ($registration['name'] ?? null) !== $name || ($registration['command'] ?? null) !== 'registerDomain'
            || ($registration['term'] ?? null) !== 1
            || ($attempt['dispatch_utc'] ?? null) !== ($renewals[0]['utc'] ?? null)
            || ($registration['dispatch_utc'] ?? null) !== ($registrations[0]['utc'] ?? null)
            || abs(time() - (new \DateTimeImmutable($attempt['dispatch_utc']))->getTimestamp()) > 900) {
            throw new \RuntimeException('Renewal continuation evidence refused.');
        }
        new \Paymenter\Extensions\Servers\Domains\Data\Money($attempt['authorized_amount'] ?? '', 'USD');
        // Claim before any new read or write. A crash consumes this one diagnostic authority.
        $old = umask(0077);
        try { $file = @fopen($directory.'/interactive-renewal-claimed', 'x'); }
        finally { umask($old); }
        if ($file === false) { throw new \RuntimeException('Renewal continuation already consumed.'); }
        try {
            if (!fwrite($file, "claimed\n") || !fflush($file) || !fsync($file)) { throw new \RuntimeException('Continuation claim failed.'); }
        } finally { fclose($file); }
        return $name;
    }
}
