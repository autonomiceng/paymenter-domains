<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Explicit, revocable account contract; future install/staff workflows supply validated acceptance. */
final class HistoryAcceptances
{
    public const KEY = 'domains_history_acceptance';
    public static function load(AccountBinding $binding, Action $action = Action::Register): ?HistoryAcceptance
    {
        $query = Server::findOrFail($binding->serverId)->settings()->where('key', self::KEY.match ($action) { Action::Renew => '_renewal', Action::Transfer => '_transfer', default => '' });
        if (DB::transactionLevel() > 0) { $query->lockForUpdate(); }
        $setting = $query->first();
        if (!$setting) { return null; }
        try {
            if (!$setting->encrypted) { throw new \DomainException; }
            $data = json_decode($setting->value, true, 16, JSON_THROW_ON_ERROR);
            if (($data['fingerprint'] ?? null) !== $binding->fingerprint || ($data['complete'] ?? null) !== true
                || ($data['currency'] ?? null) !== 'USD') { throw new \DomainException; }
            return new HistoryAcceptance($binding, $data['timezone'], $data['lag'], $data['retention'], $data['vocabulary']);
        } catch (\Throwable) { throw new \DomainException('History acceptance is unavailable.'); }
    }
    public static function save(HistoryAcceptance $acceptance, ?StaffSettingsReview $review = null): void
    {
        OperationClaims::outside(); $binding = $acceptance->binding;
        DB::transaction(static function () use ($binding, $acceptance, $review): void {
            $review?->lockActor(); $review?->lockAccounts(); $review?->check();
            if ($review && ($review->action !== 'history' || $review->serverId !== $binding->serverId)) { throw new \DomainException('History review mismatch.'); }
            $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $binding->serverId)->lockForUpdate()->firstOrFail();
            $binding->requireMatch(CatalogueAcceptances::binding($account));
            if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Unavailable server.'); }
            $key = self::KEY.(str_ends_with($acceptance->vocabulary, '_renewal_v1') ? '_renewal' : (str_ends_with($acceptance->vocabulary, '_transfer_v1') ? '_transfer' : ''));
            $setting = $server->settings()->where('key', $key)->lockForUpdate()->first()
                ?? $server->settings()->make(['key' => $key]);
            $setting->encrypted = true; $setting->type = 'string';
            $setting->value = json_encode(['fingerprint' => $binding->fingerprint, 'complete' => true, 'currency' => 'USD',
                'timezone' => $acceptance->timezone, 'lag' => $acceptance->lagSeconds, 'retention' => $acceptance->retentionSeconds,
                'vocabulary' => $acceptance->vocabulary], JSON_THROW_ON_ERROR);
            $setting->save(); $review?->record();
        });
    }
    public static function revokeLocked(AccountBinding $binding, Action $action): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Outer history transaction required.'); }
        $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
        $binding->requireMatch(CatalogueAcceptances::binding(Account::where('server_id', $binding->serverId)->lockForUpdate()->firstOrFail()));
        $suffix = match ($action) { Action::Register => '', Action::Renew => '_renewal', Action::Transfer => '_transfer', default => throw new \DomainException('History action required.') };
        $server->settings()->where('key', self::KEY.$suffix)->delete();
    }
}
