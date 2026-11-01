<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Closed Domains lines only. Amount is the recorded return, not an inferred receipt or debt amount. */
final readonly class InvoiceOutcome
{
    /** Kind/money may be mixed; lines retain each domain's closure kind. */
    public function __construct(public string $kind,public string $money,public ?string $amount,public array $domains,public array $lines) {}
}
