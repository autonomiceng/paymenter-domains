<?php
// Enom444f31c bounded refresh/follow-up; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueFreshness, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
/** No installed schedule or writer. One turn/account, bounded discovery and one read job/turn. */
final class ObservationWork
{
    public static function stopped(Domain $d): bool { return HoldingObservationWork::stopped($d); }
    public static function stoppedIds(\Illuminate\Support\Collection $domains): array { return HoldingObservationWork::stoppedIds($domains); }
    public static function currentId(Domain $d): ?int { return HoldingObservationWork::currentId($d); }
    public static function discardUnsent(Domain $d, \DateTimeImmutable $now, ?\Illuminate\Support\Collection $operations=null): bool
    { return HoldingObservationWork::discardUnsent($d,$now,$operations); }
    public function __construct(private readonly DomainObservation $observer, private readonly Clock $clock,
        private readonly ReadLimiter $budget, private readonly ?CatalogueSync $catalogue=null) {}
    private function scheduler(): \Paymenter\Extensions\Servers\Domains\Catalogue\OfferScheduler
    { return \Paymenter\Extensions\Servers\Domains\Catalogue\OfferScheduler::routed() ?? throw new \DomainException(OfferUpgrade::MESSAGE); }
    public function enroll(int $server): void { $this->scheduler()->enroll($server); }
    public function tick(int $accounts=8): array { return $this->scheduler()->tick($accounts); }
    public function resume(int $id, string $reason, StaffOperationReview|StaffSyncReview|null $review=null): void
    { $this->scheduler()->resume($id,$reason,$review); }
}
