<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, TestCatalogue};
/** A lost Register or Renew reply is settled from Enom's order history, so staff need not ask Enom support for a found order.
 * Measured on the Enom TEST account on Nov 14, 2026: 135 orders back to Oct 12 (33 days) listed on stable pages, each with a
 * complete detail. 30 days keeps a margin. TEST deployments only; Live keeps its dated activation evidence. */
final class TestOrderHistory
{
    public const TIMEZONE = 'America/Los_Angeles', LAG = 60, RETENTION = 2592000;
    public static function acceptance(AccountBinding $binding, Action $action): ?HistoryAcceptance
    {
        if (!in_array($action, [Action::Register, Action::Renew], true) || !TestCatalogue::testDeployment($binding)) { return null; }
        return new HistoryAcceptance($binding, self::TIMEZONE, self::LAG, self::RETENTION, 'enom_'.($action === Action::Renew ? 'renewal' : 'registration').'_v1');
    }
    /** A saved TEST contract shorter than the measured horizon (the earlier 24-hour value) is raised to it, keeping its timezone
     * and delay, under the same server, account and settings locks as a staff save. Outside transactions only. */
    public static function upgrade(Account $account): void
    {
        $binding = AccountBindings::binding($account);
        foreach ([Action::Register, Action::Renew] as $action) {
            if (!self::acceptance($binding, $action) || HistoryAcceptances::builtIn($binding, $action)
                || (HistoryAcceptances::load($binding, $action)?->retentionSeconds ?? self::RETENTION) >= self::RETENTION) { continue; }
            DB::transaction(static function () use ($binding, $action): void {
                Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
                $binding->requireMatch(AccountBindings::binding(Account::where('server_id', $binding->serverId)->lockForUpdate()->sole()));
                $saved = HistoryAcceptances::load($binding, $action);
                if (!$saved || HistoryAcceptances::builtIn($binding, $action) || $saved->retentionSeconds >= self::RETENTION) { return; }
                HistoryAcceptances::saveLocked(new HistoryAcceptance($binding, $saved->timezone, $saved->lagSeconds, self::RETENTION, $saved->vocabulary));
            });
        }
    }
}
