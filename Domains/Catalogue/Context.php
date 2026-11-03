<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** Context is not authorization; loaders enforce existing permissions/ownership and lock order. */
enum Context
{
    case Staff;
    case Public;
    case Locked;
    case Holder;
}
