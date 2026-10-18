<?php
// Adapted from Enom 444f31c Services/Catalogue{,Runtime}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

final class PricingRules
{
    public function rule(Tld $tld): array
    {
        if ($tld->use_default_rule) { return $this->defaultRule((int) $tld->server_id); }
        return PricingRule::validate(['mode' => $tld->pricing_mode, 'markup' => $tld->markup ?? '0', 'round_up' => $tld->round_up,
            'fixed' => ['register' => $tld->fixed_register, 'renew' => $tld->fixed_renew, 'transfer' => $tld->fixed_transfer]]);
    }

    /** One shared default across an account's TLDs; another account has its own retail table/rule. */
    public function defaultRule(int $serverId): array
    {
        $server = $this->server($serverId);
        $setting = $server->settings()->where('key', 'domains_pricing_default_rule')->first();
        $rule = $setting ? $setting->value : PricingRule::DEFAULT;
        if (!is_array($rule)) { throw new \DomainException('Invalid default pricing rule.'); }
        return PricingRule::validate($rule);
    }

    /** Callback republishes each ending independently; a failure must not hide later endings. */
    public function saveDefaultRule(int $serverId, array $rule, \Closure $republish): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Default republication requires independent transactions.'); }
        $rule = PricingRule::validate($rule);
        DB::transaction(function () use ($serverId, $rule): void {
            $server = $this->server($serverId, true);
            Account::where('server_id', $serverId)->lockForUpdate()->firstOrFail();
            $server->settings()->updateOrCreate(['key' => 'domains_pricing_default_rule'], ['type' => 'array', 'encrypted' => false, 'value' => $rule]);
        });
        $summary = ['published' => 0, 'failed' => [], 'failure_types' => []];
        foreach (Tld::where('server_id', $serverId)->where('use_default_rule', true)->orderBy('id')->get() as $tld) {
            try { $republish($tld); $summary['published']++; }
            catch (\Throwable $error) { $summary['failed'][] = '.'.$tld->tld; $summary['failure_types']['.'.$tld->tld] = $error::class; }
        }
        return $summary;
    }

    private function server(int $id, bool $lock = false): Server
    {
        $query = Server::whereKey($id); if ($lock) { $query->lockForUpdate(); }
        $server = $query->firstOrFail();
        if ($server->extension !== 'Domains' || !$server->enabled || !Account::where('server_id', $id)->exists()) {
            throw new \DomainException('Bound Domains account required.');
        }
        return $server;
    }
}
