<?php
// Adapted from Enom 444f31c Services/RegistrarMoney.php and RenewalAdmission.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{CostUnit, DateEvidence, DomainSnapshot, Evidence, ExpiryPrecision, Query, QuoteEvidence, RenewalStatus};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

/** Provider facts only; slice 23 still owns admission, expected-ID/expiry and policy checks. */
final class Renewal
{
    public static function assess(Query $query, DomainSnapshot $domain, ProtocolResult $authenticatedInfo, ProtocolResult $extend, ProtocolResult $check,
        ?ProtocolResult $expired, \DateTimeImmutable $now, \DateTimeImmutable $freshUntil): Evidence
    {
        $unknown = new Evidence(domain: $domain);
        $row = $check->value('domains.domain');
        if (is_array($row) && array_is_list($row)) { $row = count($row) === 1 ? $row[0] : null; }
        if ($extend->status !== 'success' || $check->status !== 'success' || !is_array($row)
            || !is_string($row['name'] ?? null) || strtolower($row['name']) !== $query->name || ($row['rrpcode'] ?? null) !== '211') { return $unknown; }
        $premium = array_map(Driver::flag(...), [$row['ispremium'] ?? null, $row['isplatinum'] ?? null, $row['iseap'] ?? null]);
        $ordinary = !in_array(null, $premium, true) && !in_array(true, $premium, true);
        $limits = self::limits($extend);
        if (!$ordinary || $limits === null) { return $unknown; }
        ['hold' => $hold, 'maximum' => $maximum] = $limits;
        if ($query->years > (int) $maximum) { return new Evidence(domain: $domain, renewal: RenewalStatus::Ineligible); }
        $expiry = Expiry::reconcile($extend, $domain->expiry, $authenticatedInfo);
        // A calendar day alone cannot establish which side of expiry this instant occupies.
        if (!$expiry || $expiry->precision !== ExpiryPrecision::Instant) { return $unknown; }
        $past = new \DateTimeImmutable($expiry->value) <= $now;
        $status = RenewalStatus::Ordinary;
        if ($past) {
            $status = self::expiredStatus($expired, $query, $domain, $expiry);
            if ($status !== RenewalStatus::Grace) { return new Evidence(domain: $domain, renewal: $status); }
            if ($query->years !== 1) { return new Evidence(domain: $domain, renewal: RenewalStatus::Ineligible); }
        } elseif ($hold) { return new Evidence(domain: $domain, renewal: RenewalStatus::Ineligible); }
        $price = Pricing::cents($extend->value('price')); $prices = $row['prices'] ?? null;
        $quote = null; $currency = $extend->value('currency', 'USD');
        if ($price !== null && Pricing::annual($price) !== null && Pricing::checkUsd($prices) && Pricing::cents($prices['renewal'] ?? null) === $price
            && (is_string($currency) && strtoupper($currency) === 'USD')) {
            $quote = new QuoteEvidence($query, Pricing::total($price, $query->years), CostUnit::TermTotal,
                $past ? 'enom_check_extend_expired' : 'enom_check_extend', $now, $freshUntil);
        }
        return new Evidence(domain: $domain, quote: $quote, premium: false, renewal: $status);
    }
    public static function limits(ProtocolResult $extend): ?array
    {
        if ($extend->status !== 'success') { return null; }
        $hold = Driver::flag($extend->value('registrarhold'));
        $maximum = $extend->value('maxextension');
        return $hold !== null && is_string($maximum) && preg_match('/^(?:[1-9]|10)$/D', $maximum)
            ? ['hold' => $hold, 'maximum' => (int) $maximum] : null;
    }
    private static function expiredStatus(?ProtocolResult $reply, Query $query, DomainSnapshot $domain, DateEvidence $expiry): RenewalStatus
    {
        if ($reply?->status !== 'success') { return RenewalStatus::Unknown; }
        $rows = $reply->value('domaindetail'); $count = $reply->value('domaincount');
        if (!is_array($rows) || !is_string($count) || !preg_match('/^[0-9]+$/D', $count)) { return RenewalStatus::Unknown; }
        $rows = array_is_list($rows) ? $rows : [$rows];
        if (count($rows) > 200 || (int) $count !== count($rows)) { return RenewalStatus::Unknown; }
        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['domainname'] ?? null)) { return RenewalStatus::Unknown; }
            if (strtolower($row['domainname']) === $query->name) { $matches[] = $row; }
        }
        if (count($matches) !== 1 || ($matches[0]['domainnameid'] ?? null) !== $domain->providerReference) { return RenewalStatus::Unknown; }
        $row = $matches[0]; $state = is_string($row['status'] ?? null) ? strtolower($row['status']) : null;
        $date = Expiry::read(new ProtocolResult('success', data: ['expirationdate' => $row['expiration-date'] ?? null]));
        if (!$date || substr($date->value, 0, 10) !== substr($expiry->value, 0, 10)) { return RenewalStatus::Unknown; }
        if ($state === 'extended rgp') { return RenewalStatus::ExtendedRedemption; }
        if (in_array($state, ['rgp', 'redemption', 'redemptionperiod', 'restorable'], true)) { return RenewalStatus::Redemption; }
        return $state === 'expired' && $date && substr($date->value, 0, 10) === substr($expiry->value, 0, 10)
            ? RenewalStatus::Grace : RenewalStatus::Unknown;
    }
}
