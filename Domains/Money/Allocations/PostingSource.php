<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Allocations;

enum PostingSource: string
{
    case Item = 'item';
    case Payment = 'payment';
}
