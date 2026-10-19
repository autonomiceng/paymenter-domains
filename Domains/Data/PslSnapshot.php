<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
use Paymenter\Extensions\Servers\Domains\Services\PslValidator;
/** Validated data only; construction and resolution perform no I/O. */
final readonly class PslSnapshot
{
    public string $revision;
    public array $rules;
    public function __construct(string $body, PslValidator $validator)
    { $this->rules = $validator->parse($body); $this->revision = hash('sha256', $body); }
}
