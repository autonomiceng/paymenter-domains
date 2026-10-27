<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use InvalidatesStorefront;

    private static int $bindingWriteDepth = 0;

    protected $table = 'domains_accounts';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'stable_identity', 'identity_fingerprint', 'live_site'];
    protected $casts = ['stable_identity' => 'encrypted', 'processing_enabled' => 'boolean'];
    /** @internal AccountBindings only, after provider verification, explicit OTE enrollment or site/processing guards.
     * This narrow synchronous scope is not exposed through native forms or mass assignment.
     */
    public static function withVerifiedBindingWrite(#[\SensitiveParameter] \Closure $write): mixed
    {
        self::$bindingWriteDepth++;
        try { return $write(); }
        finally { self::$bindingWriteDepth--; }
    }

    protected static function booted(): void
    {
        static::creating(static function (self $account): void {
            if (self::$bindingWriteDepth === 0) { throw new \DomainException('Account creation requires verified binding service.'); }
            $server = \App\Models\Server::find($account->server_id);
            if (!$server || $server->extension !== 'Domains' || !$server->enabled) {
                throw new \DomainException('Account requires an enabled native Domains server.');
            }
            if(\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::enrolled($account)
                &&($account->registrar_key!=='namesilo'||$account->environment!=='test')){throw new \DomainException('Sandbox enrollment cannot leave OTE.');}
            $account->stable_identity = \Paymenter\Extensions\Servers\Domains\Data\AccountBinding::canonicalIdentity($account->registrar_key, $account->stable_identity);
            $binding = new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding($account->registrar_key,
                (int) $account->server_id, \Paymenter\Extensions\Servers\Domains\Data\Environment::from($account->environment), $account->stable_identity);
            $account->account_fingerprint = $binding->fingerprint;
            $account->identity_fingerprint = hash('sha256', json_encode([$account->registrar_key, $account->environment, $account->stable_identity], JSON_THROW_ON_ERROR));
            if ($account->processing_enabled || $account->live_site !== null) { throw new \DomainException('New account processing and live site must start disabled.'); }
        });
        static::updating(static function (self $account): void {
            if (self::$bindingWriteDepth === 0 && $account->isDirty(['live_site', 'processing_enabled'])) {
                throw new \DomainException('Account site and processing require the binding service.');
            }
            if ($account->isDirty(['id', 'server_id', 'registrar_key', 'environment', 'stable_identity', 'account_fingerprint', 'identity_fingerprint'])
                || ($account->getOriginal('live_site') !== null && $account->isDirty('live_site'))) {
                throw new \DomainException('Registrar account and live site binding are immutable.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Registrar account bindings are retained.'); });
    }
}
