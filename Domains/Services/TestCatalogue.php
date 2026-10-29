<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
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

    /** Staff Sync prices: every accepted TLD in one run, or the one ending given. */
    public static function import(StaffSettingsReview $review, array $data, AccountBindings $bindings, Clock $clock): array
    {
        $review->local(static fn() => null, 'catalogue_requested');
        $phase = 'catalogue_refused';
        try {
            $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $review->serverId)->sole();
            $ending = strtolower(ltrim(trim((string)($data['ending'] ?? '')), '.'));
            $result = app(PriceSync::class)->run($account, $clock, $review, $ending === '' ? null : $ending);
            $phase = 'catalogue_finished';
            return $result;
        } finally { $review->finish($phase); }
    }

    /** Remove TEST cart lines affected by this staff withdrawal or reviewed re-import. */
    public static function withdrawCarts(AccountBinding $binding, ?string $ending = null, bool $invalidOnly = false): void
    {
        if (!self::testDeployment($binding) && !EnomLive::deployment($binding)) { return; }
        DB::afterCommit(static function() use ($binding, $ending, $invalidOnly): void {
            $products = \App\Models\Product::where('server_id', $binding->serverId)
                ->whereHas('settings', static fn($q) => $q->where('key', 'domains_catalogue')->where('value', '1'));
            if ($ending !== null) {
                $ids = \Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $binding->serverId)->where('tld', $ending)->pluck('id')->map(static fn($id) => (string)$id);
                $products->whereHas('settings', static fn($q) => $q->where('key', 'domains_tld_id')->whereIn('value', $ids));
            }
            $items = \App\Models\CartItem::whereIn('product_id', $products->pluck('id'));
            if (!$invalidOnly) { $items->delete(); return; }
            foreach ($items->get() as $item) {
                try {
                    $mapping = app(NativeCatalogue::class)->mapping($item->product, $item->plan);
                    if ($mapping) {
                        app(SellerPolicies::class)->selection($mapping['tld']->tld, $mapping['action'], $mapping['term'], app(Clock::class)->now());
                        continue;
                    }
                } catch (\Throwable $error) {
                    if (!$error instanceof \DomainException && !$error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                        try { \Illuminate\Support\Facades\Log::warning('Domains cart eligibility failed.', ['cart_item_id' => (int)$item->id, 'exception_class' => $error::class]); }
                        catch (\Throwable) { /* Continue withdrawing unverified lines if diagnostics fail. */ }
                    }
                }
                $item->delete();
            }
        });
    }
}
