<?php
// Adapted from Enom 444f31c DomainController/DomainOrders/Catalogue; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, CanonicalName, Disposition, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, SalesPolicy, Tld};

/** Explicit customer projection. No model, seller, cost, provider text or read evidence reaches Blade. */
class Storefront
{
    /** The installed active offer adapter owns all customer price and eligibility policy. */
    protected function catalogue(): ?AcquisitionOffers
    { return \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(AcquisitionOffers::class); }

    public function __construct(private readonly SellerPolicies $sellers, private readonly PriceCatalogue $prices,
        private readonly NativeCatalogue $native, private readonly AccountBindings $bindings,
        private readonly CheckoutEligibility $eligibility, private readonly Clock $clock) {}

    private function reader(?array $endings = null): StorefrontCatalogueRead
    { return new StorefrontCatalogueRead($this->sellers, $this->native, $this->clock, $endings, $this->catalogue()); }

    private function cache(): StorefrontCatalogueCache
    { $offers = $this->catalogue(); return new StorefrontCatalogueCache($this->clock, $offers ? $offers->cacheFence(...) : null); }

    public function endings(): array
    {
        return $this->cache()->remember(function (): array {
            $rows = $this->catalogueRows(); $tests = $this->testEndings($rows);
            return array_map(static fn ($row) => [...$row, 'testEnvironment' => in_array($row['tld'], $tests, true)], $rows);
        });
    }

    /** Display-only existence check with the same warm projection and eligibility as endings(). */
    public function hasTransferOffers(): bool
    {
        $rows = $this->cache()->cachedRows();
        if ($rows !== null) {
            foreach ($rows as $row) { if ($row['transfer'] !== null) { return true; } }
            return false;
        }
        $endings = SalesPolicy::where('transfer_enabled', true)->orderBy('tld')->pluck('tld')->all();
        $read = $this->reader($endings);
        foreach ($endings as $ending) {
            try { $read->offer($ending, 'transfer', 1); return true; }
            catch (\Throwable) { /* A held ending does not rule out a later transfer offer. */ }
        }
        return false;
    }

    /** Display-only native plans for the current seller, using the same exact prices as search.
     * Retained native rows alone never make a term sellable; admission still rechecks the choice. */
    public function checkoutPlans(\App\Models\Product $product): \Illuminate\Support\Collection
    { return $this->checkoutPlanChoices($product)['plans']; }

    /** A failed local read differs from a healthy catalogue with no available terms. */
    public function checkoutPlanChoices(\App\Models\Product $product): array
    {
        try {
            $mapping = $this->native->mapping($product);
            if ($mapping === null) { return ['plans'=>collect(), 'failed'=>false, 'persistent'=>false]; }
            $ending = $mapping['tld']->tld;
            $offers = ($this->catalogue() ?? throw new \DomainException(OfferUpgrade::MESSAGE))->display([$ending]);
            $plans = collect($offers[$ending] ?? [])
                ->filter(static fn ($offer) => $offer['product']->id === $product->id)
                ->pluck('plan')->sortBy('billing_period')->values();
            return ['plans'=>$plans, 'failed'=>false, 'persistent'=>false];
        } catch (\Throwable $error) {
            // Allowlisted cause only; never exception text. Invalid saved upgrade state survives a reload.
            $cause = CartHandoffFailure::key($error);
            try {
                if (\Illuminate\Support\Facades\Cache::add('domains.cart.choices:'.$product->id.':'.$cause, true, 60)) {
                    \Illuminate\Support\Facades\Log::warning('Domains cart term choices unavailable.', ['product_id'=>(int)$product->id, 'cause'=>$cause]);
                }
            } catch (\Throwable) { /* Diagnostic failure cannot turn retained plans into offers. */ }
            return ['plans'=>collect(), 'failed'=>true, 'persistent'=>in_array($cause, CartHandoffFailure::PERSISTENT, true)];
        }
    }

    /** Current public transfer offers, bounded to the candidate suffixes of these names. */
    public function offeredTransferEndings(array $names): array
    {
        $endings = PolicyEndings::transferEnabled($names);
        $read = $this->reader($endings); $offered = [];
        foreach ($endings as $ending) {
            try { $read->offer($ending, 'transfer', 1); $offered[] = $ending; }
            catch (\Throwable) { /* An unavailable public offer has no entry link. */ }
        }
        return $offered;
    }

    private function catalogueRows(): array
    {
        $endings = SalesPolicy::orderBy('tld')->pluck('tld')->all();
        $rows = []; $read = $this->reader($endings);
        foreach ($endings as $ending) {
            $register = $transfer = $chosen = null; $terms = [];
            foreach (range(1, 10) as $term) {
                try { $offer = $read->offer($ending, 'register', $term); $chosen ??= $offer; $terms[] = $term; if ($term === 1) { $register = $offer['price']; } }
                catch (\Throwable) { /* A held term does not imply an unavailable longer term. */ }
            }
            try { $offer = $read->offer($ending, 'transfer', 1); $chosen ??= $offer; $transfer = $offer['price']; } catch (\Throwable) {}
            if (!$terms && !$transfer) { continue; }
            $renew = $chosen['renew'];
            try { $transfersOn = $read->transfersOn($ending); } catch (\Throwable) { $transfersOn = false; }
            $rows[] = ['tld' => $ending, 'price' => $register, 'register' => $register, 'transfer' => $transfer, 'transfersOn' => $transfersOn,
                'renew' => $renew, 'terms' => $terms];
        }
        return $rows;
    }

    public function testEnvironment(array $rows): bool|string
    {
        $tests = count(array_filter($rows, static fn ($row) => $row['testEnvironment'] ?? false));
        return $tests === 0 ? false : ($tests === count($rows) ? true : 'some');
    }

    /** Test labels describe the public selected seller, never a reserved domain's owner/account. */
    private function testEndings(array $rows): array
    {
        $ids = SalesPolicy::whereIn('tld', array_column($rows, 'tld'))->pluck('server_id', 'tld');
        $environments = Account::whereIn('server_id', $ids->values())->pluck('environment', 'server_id');
        return $ids->filter(static fn ($id) => ($environments[$id] ?? null) === 'test')->keys()->all();
    }

    /** Safe public result only. Any reservation has the SAME response regardless of owner or lifecycle. */
    public function check(string $input, bool $transferOnly = false): array
    {
        $name = CanonicalName::normalize($input);
        $ending = PolicyEndings::parse($name)['tld'];
        $base = ['domain' => $name, 'sld' => substr($name, 0, -strlen($ending)-1), 'tld' => $ending, 'status' => 'taken',
            'testEnvironment' => $this->testEndings([['tld' => $ending]]) !== []];
        if ($transferOnly) { $base['transferPage'] = true; }
        $read = $this->reader(); $transfer = null;
        try { $transfer = $read->offer($ending, 'transfer', 1); } catch (\Throwable) {}
        // A taken name on Register links to the transfer search when we transfer the ending. It depends on the ending
        // only, so a local reservation still gets the same response.
        if (!$transferOnly && $transfer && TransferPrecheck::endingOpen($transfer['selection'])) { $base['transferEnding'] = true; }
        if (Domain::where('reservation_name', $name)->exists()) { return $transferOnly ? $this->transferRefusal($base, 'local_unavailable') : $base; }
        $offers = [];
        foreach (range(1, 10) as $term) {
            try { $offers[$term] = $read->offer($ending, 'register', $term); } catch (\Throwable) {}
        }
        if (!$offers && !$transfer) { return $transferOnly ? $this->transferRefusal($base, 'ending') : [...$base, 'status' => 'held']; }
        if ($transferOnly && !$transfer) { return $this->transferRefusal($base, 'ending'); }
        if (!$offers) { return $this->transferResult($name, $transfer, $base, $transferOnly); }
        $selection = reset($offers)['selection'];
        $driver = $this->bindings->reader($selection->serverId);
        if ($driver->binding()->fingerprint !== $selection->fingerprint) { throw new \DomainException('Search account changed.'); }
        $started = $this->clock->now();
        // Installed contexts share interactive headroom with background work.
        $context = new RequestContext($driver->binding(), RequestOrigin::Interactive, $started->modify('+'.self::SEARCH_SECONDS.' seconds'), new RequestBudget(1), $this->clock, Runtime::limiter());
        $query = new Query($name, Action::Register, $offers ? (int) array_key_first($offers) : 1);
        $r = null; $admitUntil = $started->modify('+'.self::ADMISSION_SECONDS.' seconds');
        // A refusal here is local admission only: no request reached the registrar. Background reads give way to a waiting
        // search, so keep asking for the same single slot for a few seconds, inside the same absolute deadline.
        for ($wait = 0; ; $wait++) {
            try { $r = $driver->availability($query, $context); break; }
            catch (ReadDeferred) {}
            if ($wait >= self::ADMISSION_WAITS) { return [...$base, 'status' => 'busy']; }
            $this->waitForRead();
            if ($this->clock->now() >= $admitUntil) { return [...$base, 'status' => 'busy']; }
            $context = new RequestContext($driver->binding(), RequestOrigin::Interactive, $context->deadline, new RequestBudget(1), $this->clock, Runtime::limiter());
        }
        $driver->binding()->requireMatch($r->binding);
        $this->catalogue()?->validate($selection);
        if ($r->disposition !== Disposition::Accepted || $context->now() >= $context->deadline) {
            // Safe diagnostics only: whether the answer came too late, and the read's disposition.
            \Illuminate\Support\Facades\Log::warning('Domains search failed.',[...CartHandoffFailure::context(new EligibilityUnconfirmed($r->registrarCode),'search'),
                'read_disposition' => $r->disposition->value, 'deadline_passed' => $context->now() >= $context->deadline,
                'seconds' => $context->now()->getTimestamp() - $started->getTimestamp()]);
            return $transferOnly?$this->transferRefusal($base):$this->unchecked($base);
        }
        if ($r->evidence()->premium === true) { return $transferOnly ? $this->transferRefusal($base, 'premium') : [...$base, 'status' => 'premium']; }
        if ($r->evidence()->premium !== false || $r->evidence()->available === null) { return $transferOnly?$this->transferRefusal($base):$this->unchecked($base,answered:true); }
        if ($r->evidence()->available) {
            if ($transferOnly) { return $this->transferRefusal($base, 'transfer_not_registered'); }
            if (!$offers) { return [...$base, 'status' => 'held']; }
            $first = reset($offers);
            return [...$base, 'status' => 'available', 'price' => $first['price'], 'renew' => $first['renew'],
                'term' => (int) array_key_first($offers), 'terms' => array_map(static fn ($offer) => $offer['price'], $offers)];
        }
        return $transfer ? $this->transferResult($name, $transfer, $base, $transferOnly, registrationTaken: !$transferOnly) : $base;
    }

    /** Transfer eligibility has its own registrar/RDAP checks; registration term gates grant no authority here. */
    private function transferResult(string $name, array $transfer, array $base, bool $transferOnly, bool $registrationTaken = false): array
    {
        try {
            $this->eligibility->observe($name, $transfer['selection'], ['storefront']);
            return [...$base, 'status' => 'transferable', 'price' => $transfer['price'], 'renew' => $transfer['renew'], 'term' => 1];
        } catch (ReadDeferred) {
            return $registrationTaken ? $base : [...$base, 'status' => 'busy'];
        } catch (\Throwable $error) {
            if ($transferOnly) {
                // Only known local reason codes can reach customers, never exception/provider text.
                $reason = $error instanceof WriteHeld ? $error->reason : ($error instanceof TransferRestriction ? $error->getMessage()
                    : ($error::class === \DomainException::class && $error->getMessage() === 'Transfer ending is not accepted.' ? 'ending' : null));
                return $this->transferRefusal($base, $reason, $error instanceof TransferRestriction ? ($error->appliedDays ?? [60, 60]) : [60, 60],
                    $error instanceof TransferRestriction ? $error->retryAt : null, $error instanceof TransferRestriction && $error->registrarLocked);
            }
        }
        return $base;
    }

    /** Fixed public wording; an unknown refusal remains unknown and offers a safe next step. */
    private function transferRefusal(array $base, ?string $reason = null, array $days = [60, 60], ?\DateTimeImmutable $retryAt = null, bool $registrarLocked = false): array
    {
        $until = $retryAt ? ' Try transferring it after '.$retryAt->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y \a\t H:i').' UTC.' : ' Wait for the transfer lock to end.';
        $message = match ($reason) {
            'transfer_locked' => 'This domain is locked. Unlock this domain at your current registrar, then try again.',
            'transfer_creation_lock' => 'This domain was registered less than '.$days[0].' days ago.'.$until,
            'transfer_previous_lock' => 'This domain was transferred less than '.$days[1].' days ago.'.$until,
            'transfer_not_registered' => 'This domain isn’t registered. Check the spelling, or register it here.',
            'local_unavailable' => 'This domain cannot be transferred here. Contact support for help.',
            'ending', 'Transfer ending is not accepted.' => 'We don’t offer transfers for this ending.',
            'transfer_pending' => 'A transfer is already in progress. Wait for it to finish, then try again.',
            'transfer_redemption' => 'This domain is in redemption. Restore it at your current registrar before transferring it.',
            'transfer_pending_delete' => 'This domain is pending deletion. Contact your current registrar about recovery before transferring it.',
            'transfer_maximum_term' => 'A transfer would exceed this domain’s maximum registration term.',
            'premium', 'premium_name' => 'We don’t offer transfers for premium domains.',
            default => 'We couldn’t check this transfer right now. Try again in a few minutes.',
        };
        if ($registrarLocked && in_array($reason, ['transfer_creation_lock', 'transfer_previous_lock'], true)) { $message .= ' Unlock this domain at your current registrar before transferring it.'; }
        $known = in_array($reason, ['transfer_locked', 'transfer_creation_lock', 'transfer_previous_lock', 'transfer_not_registered',
            'local_unavailable', 'ending', 'Transfer ending is not accepted.', 'transfer_pending', 'transfer_redemption', 'transfer_pending_delete',
            'transfer_maximum_term', 'premium', 'premium_name'], true);
        $wait = in_array($reason, ['transfer_creation_lock', 'transfer_previous_lock', 'transfer_pending'], true);
        $action = in_array($reason, ['transfer_locked', 'transfer_redemption', 'transfer_pending_delete'], true);
        return [...$base, 'status' => !$known ? 'transfer_unconfirmed' : ($wait ? 'transfer_wait' : 'transfer_refused'),
            'refusalTone' => $reason === 'transfer_pending' ? 'info' : (($action || $wait) ? 'warn' : 'muted'), 'message' => $message, 'register' => $reason === 'transfer_not_registered',
            'support' => !$known || in_array($reason, ['local_unavailable', 'ending', 'Transfer ending is not accepted.', 'transfer_maximum_term', 'premium', 'premium_name'], true)];
    }

    /** A failed registrar check always says why and what to do next. */
    /** $answered: the registrar replied in time but left out whether the name is premium or free. */
    private function unchecked(array $base, bool $answered = false): array
    {
        return [...$base, 'status' => 'error', 'support' => true,
            'message' => $answered ? 'The registrar’s answer for this name was incomplete. Retry in a minute.'
                : 'We couldn’t get an answer from the registrar for this name. Retry in a minute.'];
    }

    /** One search may take this long in all. Enom TEST sometimes needs more than 10 seconds to answer a Check. */
    private const SEARCH_SECONDS = 20;
    /** Admission waits for one search: up to 25 short waits and 4 seconds, leaving the rest of the search deadline for the read. */
    private const ADMISSION_WAITS = 25;
    private const ADMISSION_SECONDS = 4;
    /** Short pause while another read holds the slot; no provider call or lock is held. */
    protected function waitForRead(): void { usleep(150000); }

    /** Server-only resolution; links and forms never carry native identities. Cart admission rechecks independently. */
    public function offer(string $ending, string $action, int $term): array
    {
        return ($this->catalogue() ?? throw new \DomainException(OfferUpgrade::MESSAGE))->offer($ending, $action, $term);
    }

    /** Exact two-decimal display; no float conversion even at the integer boundary. */
    public static function money(string $amount): string
    {
        $value = PriceCatalogue::retail($amount); [$whole, $fraction] = explode('.', $value);
        // A refund reads -$10.99, not $-10.99.
        $sign = str_starts_with($whole, '-') ? '-' : ''; $whole = ltrim($whole, '-');
        return $sign.'$'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
