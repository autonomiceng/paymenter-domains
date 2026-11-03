<?php
// Adapted from Enom 444f31c Services/Catalogue{,Runtime}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

final class PricingRules
{
    public const KEY = 'domains_pricing_default_rule';
    /** Until staff save Default pricing: count card fees, add 8%, then round up to .99. */
    private const NEW_ACCOUNT_DEFAULT = [...PricingRule::DEFAULT, 'mode' => 'markup_fee_percent', 'markup' => '8.00', 'round_up' => 'ending_99'];
    /** Fee snapshot for list reads; locked writers omit it. */
    public function rule(Tld $tld, bool $lock = false, ?array $fee = null): array
    {
        // Locks keep their order: the TLD's account first, then the business-wide rows.
        if ($tld->use_default_rule || $lock) { $this->server((int) $tld->server_id, $lock, false); }
        if ($tld->use_default_rule) { return $this->defaultRule($lock, $fee); }
        return PricingRule::validate(['mode' => $tld->pricing_mode, 'markup' => $tld->markup ?? '0', 'round_up' => $tld->round_up,
            'fixed' => ['register' => $tld->fixed_register, 'renew' => $tld->fixed_renew, 'transfer' => $tld->fixed_transfer],
            ...($tld->pricing_mode === 'markup_fee_percent' ? ['card_fee' => $fee ?? CardFee::read($lock)] : [])]);
    }

    /** One default for the whole business. Each account still publishes its own retail table from it. */
    public function defaultRule(bool $lock = false, ?array $fee = null): array
    {
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Rule locks require an outer transaction.'); }
        $setting = BusinessSetting::row(self::KEY, $lock);
        $rule = $setting ? $setting->value : self::NEW_ACCOUNT_DEFAULT;
        if (!is_array($rule)) { throw new \DomainException('Invalid default pricing rule.'); }
        if (($rule['mode'] ?? null) === 'markup_fee_percent') { $rule['card_fee'] = $fee ?? CardFee::read($lock); }
        return PricingRule::validate($rule);
    }

    /** Staff review owns the outer transaction and publishes each TLD separately. */
    public function saveDefaultRuleLocked(array $rule): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Outer rule transaction required.'); }
        UpgradeGate::legacy();
        BusinessSetting::saveLocked(self::KEY, PricingRule::validate($rule));
        StorefrontCatalogueCache::invalidateAfterCommit();
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
