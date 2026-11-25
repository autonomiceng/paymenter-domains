<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Edit, Facts, Intent, OfferState};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EndingChoices, RegistryInputs};

/** Offer edits that widen a sale, and the registrar terms and account binding a publication requires. */
final class OfferTerms
{
    /** Staff may record pending intent; the evaluator still requires independent evidence before selling. */
    public static function intentRefusal(Edit $edit, Tld $t, array $defaults, \DateTimeImmutable $now): ?string
    {
        if ($edit->kind !== 'override' && !(in_array($edit->kind, ['seller','sales_setup'], true) && $edit->intent !== null)) { return null; }
        $old = OfferState::decode($t->catalogue_intent); $new = $edit->intent;
        $sell = $new->sell ?? ($defaults['defaultSell'] ?? true);
        $longest = $new->longest ?? ($defaults['defaultLongest'] ?? 1);
        $intended = static fn (array|Intent $i): bool => (bool) ((is_array($i) ? $i['transfers'] ?? null : $i->transfers) ?? $defaults['defaultTransfers'] ?? false)
            && !in_array('transfer', (is_array($i) ? $i['stops'] ?? [] : $i->stops), true);
        // Seller row transfers for this offer's account, before and after the edit.
        $policy = DB::table('domains_sales_policies')->where('tld', $t->tld)->first();
        $selected = $policy && (int) $policy->server_id === (int) $t->server_id
            && $policy->account_fingerprint === $t->account_fingerprint && $policy->registrar_key === $t->registrar_key;
        $sellerBefore = $selected && (bool) $policy->transfer_enabled;
        $sellerEdit = in_array($edit->kind, ['seller','sales_setup'], true);
        $sellerTransfers = $sellerEdit ? in_array('transfer', $edit->actions, true)
            : $selected && ($edit->sellerTransfers ?? (bool) $policy->transfer_enabled);
        // A transfer intent is checked on its own, so a seller row that is off can't hide it. Turning the seller row
        // on later is checked against current terms too, though the intent was already saved.
        $intentWidens = $intended($new) && !$intended($old);
        $transfer = $intended($new) && $sellerTransfers || $intentWidens;
        $startsRegistration = $edit->publishesSeller() && in_array('register', $edit->actions, true)
            && (!$selected || !$policy->register_enabled);
        $widen = $startsRegistration || $sell && !($old['sell'] ?? $defaults['defaultSell'] ?? true)
            || $longest > ($old['longest'] ?? $defaults['defaultLongest'] ?? 1)
            || $intentWidens || $transfer && !($intended($old) && $sellerBefore)
            || array_diff($old['stops'] ?? [], $new->stops) !== []
            || ($old['salesPaused'] ?? false) && !$new->salesPaused;
        if ($sell && EndingChoices::hidden($t->tld)) { return 'This TLD is hidden.'; }
        if (!$widen) { return null; }
        // An override keeps the current seller's registration flag. Unbound edits retain their registration check.
        $register = $sellerEdit ? in_array('register', $edit->actions, true)
            : $sell && (!$selected || (bool) $policy->register_enabled);
        $facts = $sellerEdit ? OfferState::acquisitionFacts($edit->binding(), $t, app(Clock::class)) : OfferState::facts($t->catalogue_facts);
        return self::termsBlock($facts, $edit->binding()->registrarKey, $t->tld, $register, $longest, $transfer, $now) === null
            ? null : 'Explicit verified widening required.';
    }

    /** The registrar requirement behind 'unsupported_facts', in plain words: the first one that blocks the sale. */
    public static function unsupportedCause(?Facts $facts, string $registrarKey, string $tld): string
    {
        return match (true) {
            $facts === null => TldFacts::UNSUPPORTED[4],
            !RegistryInputs::supported($registrarKey, $tld, $facts->registryFields) => TldFacts::UNSUPPORTED[0],
            // Name a requirement only when the registrar stated it; an unknown flag falls to the unconfirmed or general words.
            $facts->requiresNameservers === true => TldFacts::UNSUPPORTED[1],
            $facts->autoRenewOnly === true => TldFacts::UNSUPPORTED[2],
            $facts->renewalSafe !== true => TldFacts::UNSUPPORTED[3],
            default => TldFacts::UNSUPPORTED[4],
        };
    }

    /** Staff may record intent while terms await refresh. A known Enom nameserver requirement
     * continues to block setup until a later observation supersedes it. */
    public static function termsBlock(?Facts $facts, string $registrarKey, string $tld, bool $register, int $longest, bool $transfer, \DateTimeImmutable $now): ?string
    {
        if ($registrarKey === 'enom' && $facts?->requiresNameservers === true) { return 'unsupported_facts'; }
        if (!$facts || !$facts->fresh($now)) { return null; }
        if (!RegistryInputs::supported($registrarKey, $tld, $facts->registryFields) || $facts->renewalSafe !== true
            || $facts->requiresNameservers !== false || $facts->autoRenewOnly !== false) { return 'unsupported_facts'; }
        if (!in_array(1, $facts->terms['renew'] ?? [], true)
            || $register && !array_filter($facts->terms['register'] ?? [], static fn ($term) => $term <= $longest)
            || $transfer && !in_array(1, $facts->terms['transfer'] ?? [], true)) { return 'unsupported_term'; }
        return null;
    }

    /** Only current registrar terms that list 1-year transfers allow them; missing or stale terms leave the row off. */
    public static function transfersSupported(Tld $t, \DateTimeImmutable $now): bool
    {
        $facts = OfferState::facts($t->catalogue_facts);
        return $facts?->fresh($now) === true && in_array(1, $facts->terms['transfer'] ?? [], true);
    }

    /** Both review and publication require every offer and price being written to match its account. */
    public static function validateGroup(iterable $offers, iterable $prices, array|\ArrayAccess $servers, array|\ArrayAccess $accounts): void
    {
        foreach ($offers as $offer) {
            $account = $accounts[$offer->server_id] ?? null;
            $server = $servers[$offer->server_id] ?? null;
            if (!$account || $server?->extension !== 'Domains' || $offer->account_fingerprint !== $account->account_fingerprint || $offer->registrar_key !== $account->registrar_key) {
                throw new \DomainException('Offer binding changed.');
            }
        }
        foreach ($prices as $price) {
            $account = $accounts[$price->server_id] ?? null;
            if (!$account || $price->account_fingerprint !== $account->account_fingerprint || $price->registrar_key !== $account->registrar_key) {
                throw new \DomainException('Price binding changed.');
            }
        }
    }
}
