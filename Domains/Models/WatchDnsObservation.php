<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
final class WatchDnsObservation extends PublicDnsObservation
{
    protected $table = 'domains_watch_observations';
}
