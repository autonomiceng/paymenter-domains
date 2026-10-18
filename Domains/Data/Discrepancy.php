<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

enum Discrepancy: string
{
    case Nameservers = 'nameservers';
    case Contacts = 'contacts';
    case Privacy = 'privacy';
    case AutoRenew = 'auto_renew';
    case Charge = 'charge';
}
