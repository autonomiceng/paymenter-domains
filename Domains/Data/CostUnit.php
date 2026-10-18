<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum CostUnit: string
{
    case TermTotal = 'term_total';
    case Annual = 'annual';
}
