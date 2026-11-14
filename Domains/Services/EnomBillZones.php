<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** The measured Enom bill date clock of one account, in an encrypted server setting beside its history contract. Each sample is
 * the zone that put the BillDate of a normal reply's own order inside its send window. Measured only while every sample agrees;
 * one conflicting sample leaves the account unmeasured. Stores the zones seen (count 1 each) and the hashed order ID that first
 * showed each, never replies. */
final class EnomBillZones
{
    public const KEY = 'domains_enom_bill_zone', ZONES = ['America/Los_Angeles', 'America/Denver'];
    private const ORDERS = 100;
    public static function measured(AccountBinding $binding, ?Server $loaded = null): ?string
    {
        $state = self::state($binding, self::setting($binding, $loaded));
        $zones = array_keys(array_filter($state['counts'] ?? []));
        return count($zones) === 1 ? $zones[0] : null;
    }
    /** Called outside transactions after a read. Only the first sample of each zone is written, so the setting changes at most
     * twice per account: it is part of every holding stamp on the server. A sample for a zone already seen, or a malformed
     * setting, changes nothing. */
    public static function record(AccountBinding $binding, array $sample): void
    {
        try {
            OperationClaims::outside();
            if (!in_array($sample['zone'] ?? null, self::ZONES, true) || !is_string($sample['order'] ?? null)) { return; }
            DB::transaction(static function () use ($binding, $sample): void {
                $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
                $binding->requireMatch(AccountBindings::binding(Account::where('server_id', $binding->serverId)->lockForUpdate()->sole()));
                $setting = $server->settings()->where('key', self::KEY)->lockForUpdate()->first();
                $state = $setting ? self::state($binding, $setting) : ['counts'=>[], 'orders'=>[]];
                $order = hash('sha256', $binding->fingerprint.':'.$sample['order']);
                if ($state === null || in_array($order, $state['orders'], true) || ($state['counts'][$sample['zone']] ?? 0) > 0) { return; }
                $state['counts'][$sample['zone']] = ($state['counts'][$sample['zone']] ?? 0) + 1;
                $state['orders'] = array_slice([...$state['orders'], $order], -self::ORDERS);
                $setting ??= $server->settings()->make(['key' => self::KEY]);
                $setting->encrypted = true; $setting->type = 'string';
                $setting->value = json_encode(['fingerprint'=>$binding->fingerprint, 'counts'=>$state['counts'], 'orders'=>$state['orders']], JSON_THROW_ON_ERROR);
                $setting->save();
            });
        } catch (\Throwable $error) { logger()->warning('Enom bill zone sample not saved.', ['exception_class'=>$error::class]); }
    }
    private static function setting(AccountBinding $binding, ?Server $loaded): mixed
    {
        if ($loaded !== null && (int)$loaded->id === $binding->serverId && $loaded->relationLoaded('settings') && DB::transactionLevel() === 0) {
            return $loaded->settings->firstWhere('key', self::KEY);
        }
        return Server::find($binding->serverId)?->settings()->where('key', self::KEY)->first();
    }
    /** Null for a malformed or foreign setting; no setting is an empty state. */
    private static function state(AccountBinding $binding, mixed $setting): ?array
    {
        if (!$setting) { return ['counts'=>[], 'orders'=>[]]; }
        try {
            $data = $setting->encrypted ? json_decode((string)$setting->value, true, 8, JSON_THROW_ON_ERROR) : null;
            if (!is_array($data) || ($data['fingerprint'] ?? null) !== $binding->fingerprint || !is_array($data['counts'] ?? null) || !is_array($data['orders'] ?? null)) { return null; }
            foreach ($data['counts'] as $zone=>$count) { if (!in_array($zone, self::ZONES, true) || !is_int($count) || $count < 0) { return null; } }
            foreach ($data['orders'] as $order) { if (!is_string($order) || !preg_match('/^[a-f0-9]{64}$/D', $order)) { return null; } }
            return ['counts'=>$data['counts'], 'orders'=>array_values($data['orders'])];
        } catch (\Throwable) { return null; }
    }
}
