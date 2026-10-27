<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class PslFailure extends \RuntimeException
{
    public function __construct(public readonly string $safeCode) { parent::__construct('Public suffix list update refused.'); }
}
