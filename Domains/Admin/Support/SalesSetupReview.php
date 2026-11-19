<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;

final class SalesSetupReview
{
    public const ANNUAL = ['register:1' => 'Register, 1 year', 'renew:1' => 'Renew, 1 year', 'transfer:1' => 'Transfer, 1 year'];

    public static function blocked(?string $reason): string
    {
        if (in_array($reason, TldFacts::UNSUPPORTED, true)) {
            return 'This registrar '.$reason.'.';
        }
        return match ($reason) {
            'Seller account off.' => 'This registrar account is off. Turn it on in Servers or choose another account.',
            'Seller price missing.' => 'No eligible prices are saved on this account.',
            'Seller price unreadable.' => 'The saved prices on this account cannot be read.',
            'Requested registrar terms are unsupported.', 'Explicit verified widening required.' =>
                'This registrar does not offer the requested terms. Check what to sell and the longest term.',
            'This TLD is hidden.' => 'This TLD is hidden.',
            'Offer or price binding changed.' => 'The saved prices belong to a different registrar account.',
            default => 'The saved TLD settings or prices could not be used. Check the TLD’s settings.',
        };
    }

    public static function priceNote(string $term, ?string $reason, bool $ordersPaused): ?string
    {
        return match ($reason) {
            null => null,
            'account_unavailable' => $ordersPaused ? 'Orders are paused' : 'Check registrar account',
            'staff_stopped' => 'Sales are off',
            'renewal_stopped' => 'Renewals are off',
            'seller_changed' => 'Off for this registrar',
            'staff_term_stopped' => str_starts_with($term, 'register:') ? 'Term is off' : 'Transfers are off',
            'unsupported_facts' => 'Registrar requirements prevent sales',
            'unsupported_term', 'provider_disabled' => 'Registrar does not offer this term',
            'unknown_facts', 'stale_facts' => 'Registrar terms need checking',
            'unknown_price' => 'Cost missing',
            'stale_price' => 'Cost out of date',
            'below_cost' => 'Below cost',
            'cost_limit' => 'Over the cost limit',
            'publication_pending' => 'Prices not published',
            'unpublished_price' => 'Price missing',
            default => 'Check TLD settings',
        };
    }

    public static function summary(array $rows): array
    {
        $counts = ['selling' => 0, 'checking' => 0, 'paused' => 0, 'ready' => 0, 'unchanged' => 0, 'blocked' => 0];
        foreach ($rows as $row) {
            $kind = $row['status'] !== 'ready' ? $row['status'] : match (true) {
                self::sells($row['prices']) => 'selling',
                $row['orders_paused'] => 'paused',
                in_array($row['facts_state'] ?? null, ['stale', 'unknown'], true) => 'checking',
                default => 'ready',
            };
            $counts[$kind]++;
        }
        return $counts;
    }

    public static function sells(array $prices): bool
    {
        foreach ($prices as $term => $price) {
            if ($term !== 'renew:1' && $price['available']) {
                return true;
            }
        }
        return false;
    }
}
