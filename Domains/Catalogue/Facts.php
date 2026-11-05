<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{Capability, Support};

/** One independently dated observation on the offer, never copied onto each cost. Null is unknown.
 * `expansion` holds the optional reviewed `term_evidence`/`renewal_families` siblings verbatim; `terms` stays the evaluator's set. */
final readonly class Facts
{
    public function __construct(public array $terms, public ?array $registryFields,
        public ?bool $renewalSafe, public ?bool $requiresNameservers, public ?bool $autoRenewOnly,
        public string $source, public \DateTimeImmutable $observedAt, public \DateTimeImmutable $freshUntil,
        public array $expansion = [])
    {
        if ($freshUntil <= $observedAt || array_diff(array_keys($expansion), ['term_evidence', 'renewal_families']) !== []
            || in_array(false, array_map('is_array', $expansion), true) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source)
            || array_diff(array_keys($terms), ['register', 'renew', 'transfer']) !== []) {
            throw new \InvalidArgumentException('Invalid offer facts.');
        }
        foreach ($terms as $action => $years) {
            if (!is_array($years)) { throw new \InvalidArgumentException('Invalid fact terms.'); }
            new Capability($years === [] ? Support::Unsupported : Support::Supported, $years);
            if ($action !== 'register' && array_diff($years, [1]) !== []) {
                throw new \InvalidArgumentException('Only annual renewal/transfer facts are supported.');
            }
        }
    }
    public function fresh(\DateTimeImmutable $now): bool { return $this->observedAt <= $now && $now < $this->freshUntil; }
}
