<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Service;
use Illuminate\Support\Facades\{DB, Event};

/** Transaction-bound native identity evidence. This does not authorize or enable adoption. */
final class HoldingIdentityLock
{
    private static ?\WeakMap $contexts = null;
    private bool $active = true;
    private ?array $prepared = null;
    private ?array $extension = null;
    private function __construct(private readonly \Illuminate\Database\Connection $connection,
        private readonly int $orderId, private readonly int $serviceId, private readonly int $ownerId, private readonly array $native) {}
    public function __clone(): void { throw new \LogicException('Identity locks cannot be cloned.'); }
    public function __serialize(): array { throw new \LogicException('Identity locks cannot be persisted.'); }
    public function __debugInfo(): array { return []; }

    public static function lockForAdoption(int $orderId, int $serviceId, ?int $staffId = null): self
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Identity locks join the outer transaction.'); }
        // Discovery selects lock keys only. Authority comes from the current locked rows below.
        $ownerId = (int) Service::whereKey($serviceId)->value('user_id');
        $native = HoldingNativeLocks::take($ownerId, $serviceId, $staffId, expectedOrderId: $orderId, adoption: true);
        $service = $native['service']; $order = $native['orders']->firstWhere('id', $orderId);
        if (!$order || (int) $service->order_id !== $orderId || (int) $service->user_id !== $ownerId
            || (int) $order->user_id !== $ownerId || (int) $native['user']->id !== $ownerId) {
            throw new \DomainException('Holding native authority changed.');
        }
        self::$contexts ??= new \WeakMap;
        if (HookRegistration::claim(Event::getFacadeRoot(),self::class)) {
            foreach ([\Illuminate\Database\Events\TransactionBeginning::class, \Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $event) {
                Event::listen($event, static function ($e): void {
                    foreach (self::$contexts as $context => $_) {
                        if ($context->connection === $e->connection
                            && ($e instanceof \Illuminate\Database\Events\TransactionRolledBack || $e->connection->transactionLevel() === 0
                                || ($e instanceof \Illuminate\Database\Events\TransactionBeginning && $e->connection->transactionLevel() === 1))) {
                            $context->active = false;
                        }
                    }
                });
            }
        }
        $context = new self(DB::connection(), $orderId, $serviceId, $ownerId, $native);
        self::$contexts[$context] = true; return $context;
    }

    /** Require the preview owner. A3 must use locking reads/unique constraints, never snapshot absence.
     * Any database exception must abort the entire outer transaction; never catch and continue.
     */
    public function owners(int $expectedOwnerId): array
    {
        if (!$this->active || !isset(self::$contexts[$this]) || DB::connection() !== $this->connection || DB::transactionLevel() !== 1 || !$this->connection->getPdo()->inTransaction()) {
            throw new \LogicException('Identity lock expired.');
        }
        if ($expectedOwnerId !== $this->ownerId) { throw new \DomainException('Holding native authority changed.'); }
        return ['order_id' => $this->orderId, 'service_id' => $this->serviceId,
            'order_user_id' => $this->ownerId, 'service_user_id' => $this->ownerId];
    }

    /** Retained native rows; callers must not reacquire the prefix after ledger locks. */
    public function nativeRows(int $ownerId): array
    { $this->owners($ownerId); return $this->native; }

    public function extensionRows(): array
    { $this->owners($this->ownerId); return $this->extension ?? throw new \LogicException('Prepare the extension prefix first.'); }

    /** Complete the extension prefix before locking a domain or adoption. Native rows are retained. */
    public function nativeIdentity(\Paymenter\Extensions\Servers\Domains\Models\Domain $domain): array
    {
        $this->owners((int) $domain->user_id);
        if ((int) $domain->service_id !== $this->serviceId) { throw new \DomainException('Holding native authority changed.'); }
        $key = [$domain->user_id, $domain->service_id, $domain->server_id, $domain->domain, $domain->tld, $domain->registrar_key, $domain->account_fingerprint];
        if ($this->prepared !== null) {
            if ($this->prepared['key'] !== $key) { throw new \DomainException('Prepared holding identity changed.'); }
            return $this->prepared['identity'];
        }
        $server = \App\Models\Server::whereKey($domain->server_id)->lockForUpdate()->firstOrFail();
        $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $server->id)->lockForUpdate()->firstOrFail();
        $settings = $server->settings()->orderBy('id')->lockForUpdate()->get();
        $tld = \Paymenter\Extensions\Servers\Domains\Models\Tld::where(['server_id' => $server->id, 'tld' => $domain->tld])->lockForUpdate()->firstOrFail();
        if ($server->extension !== 'Domains' || $account->registrar_key !== $domain->registrar_key
            || $account->account_fingerprint !== $domain->account_fingerprint) { throw new \DomainException('Holding account changed.'); }
        $prices = \Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id' => $server->id, 'tld' => $tld->tld, 'action' => 'renew'])->orderBy('id')->lockForUpdate()->get();
        $this->extension = compact('server', 'account', 'settings', 'tld', 'prices');
        $identity = HoldingNativeIdentity::capture($domain, $this->native, $tld);
        $this->prepared = compact('key', 'identity'); return $identity;
    }
}
