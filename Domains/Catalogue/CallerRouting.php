<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, CartAdmission, CheckoutEligibility,
    EnomTestRegistration, EnomTestRenewal, EnomTestTransfer, HoldingObservationWork, NativeCatalogue, PayCheck,
    PriceCatalogue, Runtime, RuntimePorts, SellerPolicies, StaffSettingsWrites, Storefront};

/** One construction point. A private inspection never grants execution or caches future services. */
final class CallerRouting
{
    private static ?array $probe = null;
    private static ?array $active = null;
    private static ?int $generation = null;
    public static function reset(): void { self::$active = null; self::$generation = null; }
    public static function legacy(\Closure $body): mixed
    { if (UpgradeGate::routeState()['state'] === 'active') { throw new \DomainException('Legacy catalogue path is superseded.'); } return $body(); }
    public static function resolve(string $type): ?object
    {
        if (self::$probe !== null) { return self::$probe[$type] ?? null; }
        $state = UpgradeGate::routeState();
        if ($state['state'] !== 'active') { return null; }
        if (self::$generation !== $state['generation']) {
            $contract = self::contract();
            if (!hash_equals($contract['id'], $state['contract'])) { throw new \DomainException('Installed catalogue contract changed.'); }
            self::$active = self::composition($state['generation']); self::$generation = $state['generation'];
        }
        return self::$active[$type] ?? null;
    }

    /** Every invocation and write retains its captured generation. Account prefix precedes gate. */
    public static function current(int $generation, ?int $server = null): void
    {
        if (DB::transactionLevel() > 0 && $server !== null) {
            Server::whereKey($server)->lockForUpdate()->firstOrFail();
            \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->lockForUpdate()->sole();
        }
        UpgradeGate::active($generation);
    }

    private static function composition(int $generation): array
    {
        $clock = app(Clock::class); $bindings = app(AccountBindings::class);
        $current = static function (?int $server = null) use ($generation): void { self::current($generation, $server); };
        $authorize = static function ($value, ?Context $context = null) use ($current): void {
            if ($context === Context::Staff) { Staff::requireView(); }
            // Locked readers validate after their WHOLE native/server/account prefix, never before it.
            if (DB::transactionLevel() === 0) { $current(); }
        };
        $feature = static fn (AccountBinding $b, ?string $action): bool => self::feature($b, $action, false);
        $ports = new CallerPorts(new Projection(app(NativeCatalogue::class)), $clock, $bindings, $authorize, $feature, current: $current);
        $writer = $ports->writer();
        $work = (new OfferPorts($bindings, $clock, $generation, $current))->worker($writer);
        $scheduler = new OfferScheduler($work, app(HoldingObservationWork::class), $clock, $current);
        $caps = new Capabilities($ports, $clock, static function () use ($current): void { $current(); });
        return [CallerPorts::class => $ports, Writer::class => $writer, OfferWork::class => $work,
            OfferScheduler::class => $scheduler, Capabilities::class => $caps,
            AcquisitionOffers::class => new AcquisitionOffers($ports, $bindings, app(NativeCatalogue::class)),
            StaffCommands::class => new StaffCommands($writer, $bindings, $work->wake(...), $work->sync(...)),
            StaffCatalogue::class => new StaffCatalogue($ports, $clock, $work, static function (AccountBinding $b) use ($bindings, $current): bool {
                $current(); try { $bindings->offerCredentialRevision($b); return true; } catch (\DomainException) { return false; }
            }),
            OfferReadiness::class => new OfferReadiness(new OfferReader($clock, $authorize,
                static function ($b, $s, $a, $q) use ($bindings): bool {
                    try { $bindings->requireOfferAccount($b, $s, $a); return self::feature($b, $q->action, true); }
                    catch (\DomainException) { return false; }
                }, $current), $caps, $clock)];
    }

    /** Local deployment/protocol gates, independent of price readiness. Missing NS runtime stays closed. */
    private static function feature(AccountBinding $b, ?string $action, bool $setup): bool
    {
        if ($b->registrarKey !== 'enom') { return false; }
        return match ($action) {
            'register' => $setup ? EnomTestRegistration::enabled($b) : EnomTestRegistration::sellable($b),
            'renew' => $setup ? EnomTestRenewal::enabled($b) : EnomTestRenewal::accepted($b),
            'transfer' => $setup ? EnomTestTransfer::enabled($b) : EnomTestTransfer::accepted($b),
            default => false,
        };
    }

    /** Compiled installed contract, not caller flags, adapter lists or a compatibility receipt. */
    public static function contract(): array
    {
        if (self::$probe !== null) { throw new \LogicException('Nested catalogue inspection refused.'); }
        $routes = [];
        try {
            self::$probe = self::composition(UpgradeGate::generation() + 1);
            foreach ([CallerPorts::class, Capabilities::class, OfferReadiness::class, StaffCatalogue::class, OfferScheduler::class] as $type) {
                if ($type::routed() !== self::$probe[$type]) { throw new \DomainException('Incomplete installed catalogue routing.'); }
                $routes[$type] = $type;
            }
            foreach ([CartAdmission::class => AcquisitionOffers::class, CheckoutEligibility::class => AcquisitionOffers::class,
                Storefront::class => AcquisitionOffers::class, PayCheck::class => AcquisitionOffers::class,
                NativeCatalogue::class => StaffCommands::class, StaffSettingsWrites::class => StaffCommands::class,
                SellerPolicies::class => AcquisitionOffers::class, PriceCatalogue::class => CallerPorts::class] as $type => $adapter) {
                $object = app($type);
                $method = new \ReflectionMethod($object, 'catalogue');
                if ($method->invoke($object) !== self::$probe[$adapter]) { throw new \DomainException('Incomplete installed catalogue adapter: '.$type); }
                $routes[$type] = $adapter;
            }
            if (Runtime::observations() !== self::$probe[OfferScheduler::class]
                || app(RuntimePorts::class)->initialManagedRecordProducer() !== [Runtime::class, 'initialManagedRecordObservations']) {
                throw new \DomainException('Incomplete installed catalogue runtime.');
            }
            $routes[Runtime::class] = OfferScheduler::class;
            $routes['initialManagedRecordObservations'] = Runtime::class;
            $writer = self::$probe[Writer::class]; $work = self::$probe[OfferWork::class];
            foreach ([[self::$probe[CallerPorts::class], 'writer', $writer], [$work, 'writer', $writer],
                [self::$probe[StaffCommands::class], 'writer', $writer], [self::$probe[OfferScheduler::class], 'offers', $work],
                [self::$probe[StaffCatalogue::class], 'work', $work]] as [$object, $property, $expected]) {
                if ((new \ReflectionProperty($object, $property))->getValue($object) !== $expected) {
                    throw new \DomainException('Catalogue writer or consumer ownership differs.');
                }
            }
            ksort($routes);
            return ['id' => hash('sha256', OfferState::json($routes)), 'routes' => $routes];
        } finally { self::$probe = null; }
    }
}
