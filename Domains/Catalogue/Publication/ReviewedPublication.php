<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication;

use App\Models\Server;
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CatalogueActivation, Edit, Intent, OfferState, Projection, Revision, SalesSetupDraft};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{FreshTransaction, PricingRule, StaffSettingsReview};

/** One reviewed batch, with the complete shared prefix locked before any row mutation. */
final class ReviewedPublication
{
    private const ALLOWED = ['acceptance' => ['defaults', 'override'], 'override' => ['override'], 'remove_override' => ['override'],
        'tld_settings' => ['override', 'retail'], 'seller' => ['seller'], 'publish' => ['republish'], 'import' => ['sync']];

    public function __construct(private readonly OfferPublication $publication, private readonly Projection $projection,
        private readonly Clock $clock, private readonly \Closure $authorize, private readonly ?\Closure $current) {}

    /** @return array{rows:array<string,Revision>,failures:array<string,string>} */
    public function apply(StaffSettingsReview $review, array|SalesSetupDraft $entries, ?\Closure $stamp = null): array
    {
        if ($entries instanceof SalesSetupDraft) { return $this->salesSetup($review, $entries); }
        if ($stamp === null) { throw new \LogicException('Catalogue review stamp required.'); }
        if (DB::transactionLevel() !== 0 || count($entries) < 1 || count($entries) > 25) { throw new \LogicException('Reviewed batches require 1 to 25 commands and an outer transaction.'); }
        [$groups, $all] = $this->groups($review, $entries);
        return DB::transaction(function () use ($review, $entries, $stamp, $groups, $all): array {
            $native = $this->locks($review, array_values($all), array_map(static fn ($e) => $e[0]->tld(), $entries));
            $review->check(false, $stamp);
            [$accepted, $refused] = $this->admit($entries);
            if (!$accepted) { throw new \DomainException(reset($refused)); }
            $results = $this->writeAccepted($review, $entries, $accepted, $groups, $native);
            $review->record('completed', ['rows' => array_keys($results), 'failures' => $refused]);
            return ['rows' => $results, 'failures' => $refused];
        });
    }

    private function salesSetup(StaffSettingsReview $review, SalesSetupDraft $entries): array
    {
        if ($review->action !== 'sales_setup' || $review->serverId !== $entries->serverId || $review->tldId !== null
            || $review->actorId !== (int) Auth::id() || DB::transactionLevel() !== 0) { throw new \LogicException('Target account sales review required.'); }
        $seeds = Tld::whereIn('tld', $entries->suffixes)->orderBy('id')->get()->groupBy('tld');
        foreach ($seeds as $group) { if ($group->count() > 500) { throw new \DomainException('Oversized offer group.'); } }
        return FreshTransaction::run(function () use ($review, $entries, $seeds): array {
            $native = $this->locks($review, $seeds->collapse()->all(), $entries->suffixes);
            $plan = $entries->plan();
            $stamp = $plan['stamp'];
            $review->check(false, static function () use (&$stamp): string { return $stamp; });
            $saved = [];
            foreach ($plan['entries'] as $name => [$command, $revision]) {
                $group = $seeds[$name] ?? throw new \DomainException('Offer group changed.');
                if (!$command->publishesSeller()) { $group = $group->where('server_id', $command->binding()->serverId); }
                $this->publication->write($command, $revision, $group->values()->all(), $native, true);
                $saved[] = $name;
            }
            return $entries->receipt($review, $plan, $saved, $stamp);
        });
    }

    /** Authorize each edit against the review scope and collect its offer group before any lock.
     * @return array{0:list<list<Tld>>,1:array<int,Tld>} */
    private function groups(StaffSettingsReview $review, array $entries): array
    {
        $groups = []; $all = [];
        foreach ($entries as [$command, $revision]) {
            if (!$command instanceof Edit || !$revision instanceof Revision) { throw new \InvalidArgumentException('Reviewed edits required.'); }
            ($this->authorize)($command, null);
            $scope = Tld::where('server_id', $review->serverId)->where('tld', $command->tld());
            if ($review->tldId !== null) { $scope->whereKey($review->tldId); }
            if (!in_array($command->kind, self::ALLOWED[$review->action] ?? [], true) || !$scope->exists()
                || $command->kind !== 'seller' && $command->binding()->serverId !== $review->serverId) { throw new \DomainException('Catalogue review scope mismatch.'); }
            $q = Tld::where('tld', $command->tld());
            if ($command->kind !== 'seller') { $q->where('server_id', $command->binding()->serverId); }
            $seeds = $q->orderBy('id')->get()->all();
            if (!$seeds || count($seeds) > 500) { throw new \DomainException('Missing or oversized offer group.'); }
            $groups[] = $seeds;
            if ($command->kind !== 'defaults') { foreach ($seeds as $seed) { $all[$seed->id] = $seed; } }
        }
        return [$groups, $all];
    }

    /** Every revision must match, even on rows whose business intent is refused.
     * @return array{0:array<int,true>,1:array<string,string>} */
    private function admit(array $entries): array
    {
        $accepted = $refused = [];
        foreach ($entries as $i => [$command, $expected]) {
            $t = Tld::where('server_id', $command->binding()->serverId)->where('tld', $command->tld())->sole();
            $d = $this->defaults($command, $t);
            OfferState::revision($t, $d, DB::table('domains_sales_policies')->where('tld', $t->tld)->first())->requireMatch($expected);
            $reason = OfferTerms::intentRefusal($command, $t, $d, $this->clock->now());
            if ($command->kind === 'override' && in_array('renew', $command->intent->stops, true)
                && Domain::where('server_id', $t->server_id)->where('tld', $t->tld)
                    ->where('account_fingerprint', $t->account_fingerprint)->whereNotNull('reservation_name')->lockForUpdate()->exists()) {
                $reason = 'Retained holders require annual renewal support.';
            }
            if ($reason !== null) { $refused[$command->tld()] = $reason; } else { $accepted[$i] = true; }
        }
        return [$accepted, $refused];
    }

    /** @return array<string,Revision> */
    private function writeAccepted(StaffSettingsReview $review, array $entries, array $accepted, array $groups, ?array $native): array
    {
        $results = []; $sales = []; $defaultsChanged = false;
        foreach ($entries as $i => [$command, $expected]) {
            if (!isset($accepted[$i])) { continue; }
            $t = Tld::where('server_id', $command->binding()->serverId)->where('tld', $command->tld())->sole();
            $policy = DB::table('domains_sales_policies')->where('tld', $t->tld)->first();
            $current = OfferState::revision($t, $this->defaults($command, $t), $policy);
            if ($command->kind === 'defaults') { $defaultsChanged = $this->defaultsChanged($command, $t); }
            $salesEdit = $review->action === 'acceptance' && $command->kind === 'override';
            [$before, $after] = $salesEdit ? self::salesChange(OfferState::decode($t->catalogue_intent), $command->intent) : [[], []];
            $results[$command->tld()] = $this->publication->write($command, $current, $groups[$i], $native, true);
            if (!$salesEdit) { continue; }
            $transferBefore = (bool) ($policy->transfer_enabled ?? false);
            $transferAfter = (bool) DB::table('domains_sales_policies')->where('tld', $t->tld)->value('transfer_enabled');
            if ($transferBefore !== $transferAfter) { $before['transfer'] = $transferBefore; $after['transfer'] = $transferAfter; }
            if ($after !== []) { $sales[$command->tld()] = ['old' => $before, 'new' => $after]; }
        }
        if ($review->action === 'acceptance') { $review->salesRows($sales, $defaultsChanged); }
        return $results;
    }

    /** Saved defaults that can't be read always differ from the valid ones replacing them. */
    private function defaultsChanged(Edit $command, Tld $t): bool
    {
        $newDefaults = get_object_vars($command->intent);
        $newDefaults['maxAnnualCost'] = PricingRule::wholesale($newDefaults['maxAnnualCost']);
        try {
            $saved = OfferState::decode(Account::where('server_id', $t->server_id)->sole()->catalogue_defaults);
            $oldDefaults = get_object_vars(new Intent(...array_intersect_key($saved, get_object_vars(new Intent))));
            $oldDefaults['maxAnnualCost'] = PricingRule::wholesale($oldDefaults['maxAnnualCost']);
            return !OfferState::equivalent($oldDefaults, $newDefaults);
        } catch (\Throwable) { return true; }
    }

    /** The audited sale choices an acceptance override changes.
     * @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private static function salesChange(array $old, Intent $intent): array
    {
        $before = $after = [];
        $choice = static fn ($value) => $value === null ? null : ($value ? 'sell' : 'skip');
        foreach (['choice' => 'sell', 'longest' => 'longest', 'transfer' => 'transfers'] as $label => $field) {
            $was = $old[$field] ?? null; $now = $intent->$field;
            if ($was === $now) { continue; }
            $before[$label] = $field === 'sell' ? $choice($was) : $was;
            $after[$label] = $field === 'sell' ? $choice($now) : $now;
        }
        return [$before, $after];
    }

    private function locks(StaffSettingsReview $review, array $seeds, array $endings): ?array
    {
        $review->lockActor();
        $native = $seeds ? $this->projection->lock($seeds) : null;
        // Legacy reviewed publication takes this same native -> all servers/accounts prefix.
        $review->lockAccounts();
        if ($this->current) { ($this->current)(); } else { CatalogueActivation::active(CatalogueActivation::generation()); }
        $names = Tld::where('server_id', $review->serverId)->when($review->tldId !== null, fn ($q) => $q->whereKey($review->tldId))->pluck('tld')->all();
        $names = array_values(array_unique([...$names, ...$endings])); sort($names, SORT_STRING);
        DB::table('domains_sales_policies')->whereIn('tld', $names)->orderBy('tld')->lockForUpdate()->get();
        Tld::whereIn('tld', $names)->orderBy('id')->lockForUpdate()->get();
        DB::table('domains_prices')->whereIn('tld', $names)->orderBy('id')->lockForUpdate()->get();
        DB::table('settings')->where('settingable_type', (new Server)->getMorphClass())
            ->whereIn('settingable_id', Server::where('extension', 'Domains')->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        return $native;
    }

    /** A defaults save replaces defaults that can't be read; every other command still needs them readable. */
    private function defaults(Edit $command, Tld $t): array
    {
        $raw = Account::where('server_id', $t->server_id)->sole()->catalogue_defaults;
        return $command->kind === 'defaults' ? OfferState::replaceableDefaults($raw, (int) $t->server_id) : OfferState::decode($raw);
    }
}
