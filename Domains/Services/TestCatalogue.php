<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueFreshness, Environment, RequestBudget, RequestContext, RequestOrigin};

/** TEST display/cart authority only. Checkout still requires the independent processing guard. */
final class TestCatalogue
{
    public static function enabled(AccountBinding $binding): bool
    {
        return config('domains.enom_catalogue.enabled') === true && self::testDeployment($binding);
    }

    /** Identity-mode TEST accounts never enroll an automatic catalogue refresh, including opt-out. */
    public static function testDeployment(AccountBinding $binding): bool
    {
        return config('domains.enom_identity.environment') === 'test'
            && $binding->registrarKey === 'enom' && $binding->environment === Environment::Test;
    }

    public static function import(StaffSettingsReview $review, array $data, AccountBindings $bindings, Clock $clock): void
    {
        $review->local(static fn() => null, 'catalogue_requested');
        $phase = 'catalogue_refused';
        try {
            $account = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $review->serverId)->sole();
            $binding = CatalogueAcceptances::binding($account);
            if (!self::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
            $ending = strtolower(ltrim(trim((string)($data['ending'] ?? '')), '.'));
            $accepted = CatalogueAcceptances::load($binding);
            if (!isset($accepted->endings[$ending])) { throw new \DomainException('Catalogue ending is not accepted.'); }
            $reader = $bindings->reader($review->serverId);
            $context = new RequestContext($binding, RequestOrigin::Interactive, $clock->now()->modify('+25 seconds'),
                new RequestBudget(15), $clock, Runtime::limiter(), stopOnReadFailure: true);
            if (!$reader instanceof \Paymenter\Extensions\Servers\Domains\Registrars\Enom\CataloguePreview
                || !in_array($ending, $reader->catalogue($context)->facts()['tlds'] ?? [], true)) {
                throw new \DomainException('Catalogue import unavailable.');
            }
            $result = app(CatalogueSync::class)->refresh($review->serverId, $context, new CatalogueFreshness, [$ending], $review);
            if ($result !== ['synced' => 1, 'failed' => []]) { throw new \DomainException('Catalogue import unavailable.'); }
            app(SellerPolicies::class)->initialize($ending);
            self::withdrawCarts($binding, $ending);
            $phase = 'catalogue_finished';
        } finally { $review->finish($phase); }
    }

    /** Remove TEST cart lines affected by this staff withdrawal or reviewed re-import. */
    public static function withdrawCarts(AccountBinding $binding, ?string $ending = null, bool $invalidOnly = false): void
    {
        if (!self::testDeployment($binding)) { return; }
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
                } catch (\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException) { /* Withdraw only ineligible lines. */ }
                $item->delete();
            }
        });
    }
}
