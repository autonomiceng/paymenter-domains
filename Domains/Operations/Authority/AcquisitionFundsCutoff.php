<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

enum AcquisitionFundsCutoff
{
    case Proceed;
    case Wait;
    case RetiredForCredit;
}
