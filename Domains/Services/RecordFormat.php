<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Read-only encrypted inventory codec. No canonicalization, writes or completeness inference. */
final class RecordFormat
{
    public const FAMILIES = ['dns','srv','ds','glue'];
    public static function read(array $records, string $registrar): ?array
    {
        if (!is_array($records['families'] ?? null)) { return null; }
        $keys = array_intersect(['format','capability_revision','expected_families'], array_keys($records));
        if (!$keys) {
            // Old NameSilo discovery proved glue only. Old Enom record authority is retained.
            if ($registrar === 'namesilo') {
                foreach (['dns','srv','ds'] as $family) { $records['families'][$family] = ['state'=>'unknown']; }
            }
            return $records;
        }
        if (count($keys) !== 3 || ($records['format'] ?? null) !== 1
            || !in_array($registrar, ['enom','namesilo'], true)
            || ($records['capability_revision'] ?? null) !== $registrar.'-records-1'
            || !is_array($records['expected_families'] ?? null)
            || count($records['expected_families']) !== 4
            || array_diff(self::FAMILIES, array_keys($records['expected_families']))
            || !is_array($records['families'] ?? null)) { return null; }
        foreach (self::FAMILIES as $family) {
            $expectation = $records['expected_families'][$family];
            if (!in_array($expectation, ['supported','unsupported'], true)) { return null; }
            $f = $records['families'][$family] ?? null;
            if ($expectation === 'unsupported' || !is_array($f)
                || !in_array($f['state'] ?? null, ['complete','partial','unknown'], true)
                || !is_array($f['records'] ?? null) || count($f['records']) > 50
                || ($family !== 'glue' && !array_is_list($f['records']))) {
                $records['families'][$family] = ['state'=>'unknown'];
            }
        }
        return $records;
    }
}
