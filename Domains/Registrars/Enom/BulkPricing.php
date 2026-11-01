<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

/** Selected source fields only; observing a reseller price does not establish its cost semantics. */
final class BulkPricing
{
    public static function rows(ProtocolResult $reply): ?array
    {
        if ($reply->status !== 'success') { return null; }
        $rows = $reply->value('pricestructure.product', $reply->value('pricestructure.tld'));
        if (!is_array($rows) || $rows === []) { return null; }
        if (!array_is_list($rows)) { $rows = [$rows]; }
        $count = $reply->value('count', $reply->value('pricestructure.count'));
        if ($count !== null && (!is_string($count) || !preg_match('/^[0-9]+$/D', $count) || (int) $count !== count($rows))) { return null; }
        $out = [];
        foreach ($rows as $row) {
            $tld = is_array($row) ? ($row['tld'] ?? null) : null;
            if (!is_string($tld) || strlen($tld) > 253) { return null; }
            $tld = strtolower($tld);
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $tld) || isset($out[$tld])) { return null; }
            $parsed = ['tld' => $tld];
            foreach (['registerprice', 'resellerpricereg', 'renewprice', 'resellerpricerenew', 'transferprice', 'resellerpricetran', 'rgpprice', 'resellerpricergp'] as $field) {
                $value = $row[$field] ?? null;
                // Keep exact decimal source strings, including disabled-price sentinels, without rounding.
                if ($value !== null && $value !== '' && (!is_string($value) || !preg_match('/^-?[0-9]{1,8}(?:\.[0-9]{1,8})?$/D', $value))) { return null; }
                $parsed[$field] = $value === '' ? null : $value;
            }
            foreach (['registerenabled', 'renewenabled', 'transferenabled', 'rgpenabled'] as $field) {
                $parsed[$field] = Driver::flag($row[$field] ?? null);
            }
            $minimum = $row['minimumregistration'] ?? null;
            if ($minimum !== null && (!is_string($minimum) || !preg_match('/^[1-9][0-9]?$/D', $minimum))) { return null; }
            $parsed['minimumregistration'] = $minimum;
            $out[$tld] = $parsed;
        }
        return $out;
    }
}
