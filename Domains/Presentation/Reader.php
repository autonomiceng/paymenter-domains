<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Who reads a page. Both read the same words; only a state's tone and the detail under it can differ. */
enum Reader
{
    case Staff;
    case Customer;
}
