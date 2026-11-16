<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Applied and NoChange committed; Deferred and Failed rolled back everything. */
enum MoneyOutcome
{
    case Applied;
    case NoChange;
    case Deferred;
    case Failed;
}
