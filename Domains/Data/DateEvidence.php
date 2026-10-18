<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class DateEvidence
{
    public function __construct(public string $value, public ExpiryPrecision $precision,
        public string $source, public ?string $timezone = null)
    {
        if (str_contains($value, "\0") || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source)) { throw new \InvalidArgumentException('Invalid date provenance.'); }
        $format = $precision === ExpiryPrecision::Date ? '!Y-m-d' : '!Y-m-d\TH:i:sP';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format(substr($format, 1)) !== $value
            || ($precision === ExpiryPrecision::Date && $timezone !== null)
            || ($precision === ExpiryPrecision::Instant && $timezone === null)) {
            throw new \InvalidArgumentException('Invalid date precision or value.');
        }
        if ($timezone !== null) {
            try { $zone = new \DateTimeZone($timezone); }
            catch (\Exception|\ValueError $error) { throw new \InvalidArgumentException('Invalid date timezone.', 0, $error); }
            if ($date->setTimezone($zone)->format('P') !== $date->format('P')) {
                throw new \InvalidArgumentException('Date offset contradicts timezone provenance.');
            }
        }
    }
}
