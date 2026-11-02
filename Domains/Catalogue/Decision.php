<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

final readonly class Decision implements \JsonSerializable
{
    public function __construct(public string $tld, public string $action, public int $term,
        public bool $available, public ?string $retail, public Revision $revision,
        public ?string $reason = null, public ?string $warning = null, public ?string $staffCost = null) {}
    public function jsonSerialize(): array
    {
        return ['tld' => $this->tld, 'action' => $this->action, 'term' => $this->term, 'currency' => 'USD',
            'available' => $this->available, 'retail' => $this->retail, 'revision' => $this->revision,
            'reason' => $this->reason, 'warning' => $this->warning,
            ...($this->staffCost !== null ? ['cost' => $this->staffCost] : [])];
    }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
}
