<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName,ReviewedFacts};

/** Deployment facts codec only. No default suffixes, transport, policy activation or tuple grants. */
final class DsFacts
{
    public static function read(mixed $map = [], ?\DateTimeImmutable $now = null): array
    {
        if ($now === null) { return []; } // No ambient clock may accidentally extend reviewed evidence.
        $out = [];
        foreach (ReviewedFacts::entries($map) as $suffix => $fact) {
            try {
                if (!is_string($suffix) || CanonicalName::parse('example.'.$suffix, [$suffix])['tld'] !== $suffix || !is_array($fact)
                    || ($fact['suffix'] ?? null) !== $suffix || !ReviewedFacts::current($fact, $now)
                    || !in_array($fact['input'] ?? null, ['ds','key'], true)
                    || !ReviewedFacts::integers($fact['algorithms'] ?? null, 1, 255)
                    || !ReviewedFacts::integers($fact['digest_types'] ?? null, 1, 255)
                    || ($fact['expiry_policy'] ?? null) !== 'refuse'
                    || !is_array($fact['combinations'] ?? null) || !array_is_list($fact['combinations'])
                    || !$fact['combinations'] || count($fact['combinations']) > 255) { continue; }
                foreach ($fact['combinations'] as $pair) {
                    if (!is_array($pair) || count($pair) !== 2
                        || !in_array($pair['algorithm'] ?? null, $fact['algorithms'], true)
                        || !in_array($pair['digest_type'] ?? null, $fact['digest_types'], true)) { continue 2; }
                }
                $out[$suffix] = $fact;
            } catch (\Throwable) { continue; }
        }
        return $out;
    }
}
