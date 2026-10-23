<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class WatchTransferStrings
{
    public static function all(): array
    {
        return ['watch.transfer_title'=>'Transfer :domain to Beco',
            'watch.transfer_in_cart'=>'This transfer is already in your cart.',
            'watch.transfer_unavailable'=>'This domain can’t be transferred to Beco.',
            'watch.transfer_changed'=>'The price changed.',
            'watch.transfer_subject'=>'Transfer options for :domain'];
    }
}
