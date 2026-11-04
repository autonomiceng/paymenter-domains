<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferScheduler;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};

/** Built-in catalogue authority. Checkout retains its independent processing guard. */
final class TestCatalogue
{
    public static function enabled(AccountBinding $binding): bool
    {
        return EnomLive::enabled($binding,'catalogue') || (config('domains.enom_catalogue.enabled') === true && self::testDeployment($binding));
    }

    /** Identity-mode TEST accounts never enroll an automatic catalogue refresh, including opt-out. */
    public static function testDeployment(AccountBinding $binding): bool
    {
        return config('domains.enom_identity.environment') === 'test'
            && $binding->registrarKey === 'enom' && $binding->environment === Environment::Test;
    }

    /** Staff Sync prices: every accepted TLD in one run, or the one ending given, or the endings a What you sell save named. */
    public static function import(StaffSettingsReview $review, array $data, AccountBindings $bindings, Clock $clock): array
    {
        $review->local(static fn() => null, 'catalogue_requested');
        $phase = 'catalogue_refused';
        try {
            $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $review->serverId)->sole();
            $scheduler=OfferScheduler::routed() ?? throw new \DomainException(OfferUpgrade::MESSAGE);
            $scheduler->sync(AccountBindings::binding($account));$phase='catalogue_queued';
            return ['synced'=>[],'failed'=>[],'deferred'=>[],'queued'=>true];
        } finally { $review->finish($phase); }
    }

    /** Remove TEST cart lines affected by this staff withdrawal or reviewed re-import. */

}
