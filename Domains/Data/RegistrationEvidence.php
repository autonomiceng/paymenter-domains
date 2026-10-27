<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Immediate registration facts only. Purchase acceptance is never unique financial correlation. */
final readonly class RegistrationEvidence
{
    public array $corrections;
    public function __construct(public Query $query, public ?string $orderReference, public ?DateEvidence $responseExpiry,
        public ?DomainSnapshot $readback, array $corrections, public ?NameserverEvidence $nameservers = null)
    {
        if ($query->action !== Action::Register || count($corrections) > 4
            || array_diff($corrections, ['nameservers', 'contacts', 'auto_renew', 'privacy'])
            || ($orderReference !== null && !preg_match('/^[1-9][0-9]{0,19}$/D', $orderReference))
            || ($readback && $readback->name !== $query->name)) { throw new \InvalidArgumentException('Invalid registration facts.'); }
        $this->corrections = array_values(array_unique($corrections));
    }
}
