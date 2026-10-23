<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class WatchTransferStrings
{
    public static function all(): array
    {
        return ['watch.transfer_compare'=>'Compare transfer price',
            'watch.transfer_help'=>'Check the current transfer price and renewal terms before choosing whether to move this registration.',
            'watch.transfer_saving'=>'The current transfer price is :amount less than your verified renewal price.',
            'watch.transfer_owner'=>'I own this domain or am authorized to transfer it.',
            'watch.transfer_in_cart'=>'This transfer is already in your cart.',
            'watch.transfer_unavailable'=>'Transfer is not currently available for this domain.',
            'watch.transfer_changed'=>'The transfer details changed. Check the latest offer before continuing.',
            'watch.transfer_subject'=>'Transfer options for :domain'];
    }
}
