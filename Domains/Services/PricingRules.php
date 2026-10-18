<?php
// Adapted from Enom 444f31c Services/Catalogue{,Runtime}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

final class PricingRules
{
    public function rule(Tld $tld, bool $lock = false): array
    {
        if ($tld->use_default_rule) { return $this->defaultRule((int) $tld->server_id, $lock); }
        return PricingRule::validate(['mode' => $tld->pricing_mode, 'markup' => $tld->markup ?? '0', 'round_up' => $tld->round_up,
            'fixed' => ['register' => $tld->fixed_register, 'renew' => $tld->fixed_renew, 'transfer' => $tld->fixed_transfer]]);
    }

    /** One shared default across an account's TLDs; another account has its own retail table/rule. */
    public function defaultRule(int $serverId, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Rule locks require an outer transaction.'); }
        $server = $this->server($serverId, $lock, false);
        $query = $server->settings()->where('key', 'domains_pricing_default_rule');
        if ($lock) { $query->lockForUpdate(); }
        $setting = $query->first();
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
            $this->saveDefaultRuleLocked($serverId, $rule);
        });
        $summary = ['published' => 0, 'failed' => [], 'failure_types' => []];
        foreach (Tld::where('server_id', $serverId)->where('use_default_rule', true)->orderBy('id')->get() as $tld) {
            try { $republish($tld); $summary['published']++; }
            catch (\Throwable $error) { $summary['failed'][] = '.'.$tld->tld; $summary['failure_types']['.'.$tld->tld] = $error::class; }
        }
        return $summary;
    }

    /** Staff review owns the outer transaction and publishes each TLD separately. */
    public function saveDefaultRuleLocked(int $serverId, array $rule): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Outer rule transaction required.'); }
        $server = $this->server($serverId, true);
        $server->settings()->updateOrCreate(['key' => 'domains_pricing_default_rule'],
            ['type' => 'array', 'encrypted' => false, 'value' => PricingRule::validate($rule)]);
    }

    private function server(int $id, bool $lock = false, bool $requireEnabled = true): Server
    {
        $query = Server::whereKey($id); if ($lock) { $query->lockForUpdate(); }
        $server = $query->firstOrFail();
        $account = Account::where('server_id', $id); if ($lock) { $account->lockForUpdate(); }
        if ($server->extension !== 'Domains' || ($requireEnabled && !$server->enabled) || !$account->first()) {
            throw new \DomainException('Bound Domains account required.');
        }
        return $server;
    }
}
