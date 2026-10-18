<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, ExpiryPrecision};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

final class Expiry
{
    public static function read(ProtocolResult $reply): ?DateEvidence
    {
        if ($reply->status !== 'success') { return null; }
        $confirmed = null; $missing = new \stdClass;
        foreach (['registryexpdate', 'domaininfo.registryexpdate', 'expirationdate', 'getdomaininfo.status.expiration',
            'expiration', 'getextendinfo.expiration', 'order.orderdetail.expirydatetime'] as $path) {
            $value = $reply->value($path, $missing);
            if ($value === $missing) { continue; }
            $zone = new \DateTimeZone('America/Los_Angeles');
            if (is_array($value)) {
                $attributes = $value['@attributes'] ?? [];
                if (!is_array($attributes)) { return null; }
                if (array_key_exists('utc', $attributes)) { $value = $attributes['utc']; $zone = new \DateTimeZone('UTC'); }
                else { $value = $value['#text'] ?? null; }
            }
            if (!is_string($value) || ($date = self::date($value, $zone)) === null) { return null; }
            if ($confirmed !== null) {
                if (substr($confirmed->value, 0, 10) !== substr($date->value, 0, 10)) { return null; }
                if ($confirmed->value !== $date->value || $confirmed->precision !== $date->precision) {
                    $date = new DateEvidence(substr($date->value, 0, 10), ExpiryPrecision::Date, 'enom_expiry');
                }
            }
            $confirmed = $date;
        }
        return $confirmed;
    }
    private static function date(string $input, \DateTimeZone $zone): ?DateEvidence
    {
        if (!preg_match('/^(?:\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?|\d{1,2}\/\d{1,2}\/\d{4}(?: \d{1,2}:\d{2}(?::\d{2})? (?:AM|PM))?|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?) \d{1,2}, \d{4}(?: \d{1,2}:\d{2}(?::\d{2})? (?:AM|PM))?)$/iD', $input)) { return null; }
        try {
            $date = new \DateTimeImmutable($input, $zone); $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) { return null; }
            if ($date->format('u') !== '000000') { return null; } // Neutral instants cannot retain nonzero fractions.
            if (!str_contains($input, ':')) { return new DateEvidence($date->format('Y-m-d'), ExpiryPrecision::Date, 'enom_expiry'); }
            // The neutral instant contract is second precision. Never invent fractional precision.
            return new DateEvidence($date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM), ExpiryPrecision::Instant, 'enom_expiry', 'UTC');
        } catch (\Throwable) { return null; }
    }
}
