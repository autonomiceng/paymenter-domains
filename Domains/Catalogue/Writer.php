<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\DefaultPricing;
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\{OfferPublication, ReviewedPublication};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, StaffSettingsReview};

/** Catalogue writer selected by the active installation.
 * Callbacks authorize server-side commands/contexts and check LOCAL readiness, without provider I/O. */
final class Writer implements Catalogue
{
    public function __construct(private readonly Projection $projection, private readonly Clock $clock,
        private readonly \Closure $authorize, private readonly \Closure $ready, private readonly ?int $actorId = null, private readonly ?\Closure $current = null) {}

    /** Queue generation is checked again under the writer locks, before mutations. */
    public function withGuard(\Closure $current): self
    { return new self($this->projection, $this->clock, $this->authorize, $this->ready, $this->actorId, function () use ($current): void {
        if ($this->current) { ($this->current)(); } $current();
    }); }

    public function readOffers(Query $query, Context $context): array
    { return (new OfferReader($this->clock, $this->authorize, $this->ready, $this->current))->readOffers($query, $context); }

    public function apply(Command $command, Revision $expectedRevision): Revision
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Offer writer owns its native-first transaction.'); }
        if ($command instanceof Edit && $command->kind === 'sales_setup') { throw new \DomainException('Sales setup requires a staff review.'); }
        if (!$command instanceof FeedObserved && !$command instanceof FactsObserved && !$command instanceof Edit) {
            throw new \InvalidArgumentException('Unknown offer command.');
        }
        ($this->authorize)($command, null);
        $binding = $command->binding(); $name = $command->tld();
        $seller = $command instanceof Edit && $command->kind === 'seller';
        $defaultsEdit = $command instanceof Edit && $command->kind === 'defaults';
        $seedQuery = Tld::where('tld', $name);
        if (!$seller) { $seedQuery->where('server_id', $binding->serverId); }
        $seeds = $seedQuery->orderBy('id')->get()->all();
        if (!$seeds && ($command instanceof FeedObserved || $command instanceof FactsObserved)) {
            $seeds = [new Tld(['server_id' => $binding->serverId, 'registrar_key' => $binding->registrarKey,
                'account_fingerprint' => $binding->fingerprint, 'tld' => $name])];
        }
        if (!$seeds || count($seeds) > 500) { throw new \DomainException('Missing or oversized offer group.'); }
        return DB::transaction(function () use ($command, $expectedRevision, $defaultsEdit, $seeds): Revision {
            $native = $defaultsEdit ? null : $this->projection->lock($seeds);
            return $this->publication()->write($command, $expectedRevision, $seeds, $native);
        });
    }

    /** Install/upgrade recalculates old-rule publications locally, through the normal native-first writer.
     * The saved observation timestamps and issued invoices are never changed. Safe to retry after interruption. */
    public function republishRetailRule(): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Retail upgrade requires unlocked reads.'); }
        Tld::orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $tld) {
                // An unpublished own rule remains closed for its ordinary guarded recovery.
                if ($tld->catalogue_published_revision === null) { continue; }
                try {
                    if (OfferState::currentPublication($tld->catalogue_published_revision)) { continue; }
                    $account = Account::where('server_id', $tld->server_id)->sole();
                    $binding = AccountBindings::binding($account);
                    $revision = OfferState::revision($tld, OfferState::decode($account->catalogue_defaults),
                        DB::table('domains_sales_policies')->where('tld', $tld->tld)->first());
                    $this->apply(Edit::republish($binding, $tld->tld), $revision);
                } catch (\Throwable) {
                    // The writer rolls back this TLD; an install and healthy siblings can continue.
                    try { \Illuminate\Support\Facades\Log::warning('Domains retail upgrade deferred.', ['server_id' => (int) $tld->server_id, 'tld_id' => (int) $tld->id]); }
                    catch (\Throwable) { /* Logging cannot pin the installation. */ }
                }
            }
        });
    }

    /** One global review saves once; each account then publishes independently, matching the legacy F51 result. */
    public function defaultPricing(StaffSettingsReview $review, array $rule, array $fee): array
    { return (new DefaultPricing($this, $this->clock, $this->current))->publish($review, $rule, $fee); }

    /** One reviewed batch, with the complete shared prefix locked before any row mutation.
     * @return array{rows:array<string,Revision>,failures:array<string,string>} */
    public function reviewed(StaffSettingsReview $review, array|SalesSetupDraft $entries, ?\Closure $stamp = null): array
    {
        return (new ReviewedPublication($this->publication(), $this->projection, $this->clock, $this->authorize, $this->current))
            ->apply($review, $entries, $stamp);
    }

    private function publication(): OfferPublication
    { return new OfferPublication($this->projection, $this->clock, $this->authorize, $this->ready, $this->actorId, $this->current); }
}
