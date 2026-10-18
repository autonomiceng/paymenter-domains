<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class Capability
{
    public array $terms;
    public function __construct(public Support $support = Support::Unknown, array $terms = [])
    {
        if (count($terms) !== count(array_unique($terms, SORT_REGULAR))) { throw new \InvalidArgumentException('Duplicate capability terms.'); }
        $copy = [];
        foreach ($terms as $term) {
            if (!is_int($term) || $term < 1 || $term > 10) { throw new \InvalidArgumentException('Invalid capability term.'); }
            $copy[] = $term;
        }
        $this->terms = $copy;
        if ($support !== Support::Supported && $terms !== []) { throw new \InvalidArgumentException('Unverified capability cannot advertise terms.'); }
    }
    public function allows(int $years): bool { return $this->support === Support::Supported && in_array($years, $this->terms, true); }
}
