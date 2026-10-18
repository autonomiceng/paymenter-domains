<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueAcceptance, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;

final class CatalogueAcceptances
{
    public const KEY = 'domains_catalogue_acceptance';
    public static function load(AccountBinding $binding, bool $lock = false): CatalogueAcceptance
    {
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Acceptance lock requires a transaction.'); }
        $query = Server::findOrFail($binding->serverId)->settings()->where('key', self::KEY);
        if ($lock) { $query->lockForUpdate(); }
        try {
            $setting = $query->firstOrFail();
            if (!$setting->encrypted || !is_string($setting->value)) { throw new \DomainException; }
            $data = json_decode($setting->value, true, 16, JSON_THROW_ON_ERROR);
            if (($data['fingerprint'] ?? null) !== $binding->fingerprint || ($data['currency'] ?? null) !== 'USD' || ($data['unit'] ?? null) !== 'exact_term_annual') { throw new \DomainException; }
            return new CatalogueAcceptance($binding, $data['revision'], $data['endings']);
        } catch (\Throwable) { throw new \DomainException('Catalogue acceptance is unavailable.'); }
    }
    /** Explicit acceptance/revocation by the future staff workflow; not automatic discovery. */
    public static function save(AccountBinding $binding, int $expectedRevision, array $endings): CatalogueAcceptance
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Acceptance owns its lock order.'); }
        $next = new CatalogueAcceptance($binding, $expectedRevision + 1, $endings);
        $encoded = json_encode(['fingerprint' => $binding->fingerprint, 'revision' => $next->revision, 'endings' => $next->endings, 'currency' => 'USD', 'unit' => 'exact_term_annual'], JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 32768) { throw new \DomainException('Acceptance exceeds encrypted settings capacity.'); }
        return DB::transaction(static function () use ($binding, $expectedRevision, $next, $encoded): CatalogueAcceptance {
            $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $binding->serverId)->lockForUpdate()->firstOrFail();
            $binding->requireMatch(self::binding($account));
            if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Unavailable registrar server.'); }
            $setting = $server->settings()->where('key', self::KEY)->lockForUpdate()->first();
            if (($setting ? self::load($binding, true)->revision : 0) !== $expectedRevision) { throw new \DomainException('Stale acceptance revision.'); }
            $setting ??= $server->settings()->make(['key' => self::KEY]);
            $setting->encrypted = true; $setting->type = 'string';
            $setting->value = $encoded;
            $setting->save(); return $next;
        });
    }
    public static function binding(Account $account): AccountBinding
    {
        $binding = new AccountBinding($account->registrar_key, (int) $account->server_id, Environment::from($account->environment), $account->stable_identity);
        if ($binding->fingerprint !== $account->account_fingerprint) { throw new \DomainException('Catalogue binding mismatch.'); }
        return $binding;
    }
}
