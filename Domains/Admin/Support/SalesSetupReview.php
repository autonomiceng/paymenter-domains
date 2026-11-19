<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;

use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, State};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;

final class SalesSetupReview
{
    public const ANNUAL = ['register:1' => 'Register, 1 year', 'renew:1' => 'Renew, 1 year', 'transfer:1' => 'Transfer, 1 year'];

    public static function blocked(?string $reason, string $account = 'the registrar account'): string
    {
        if (in_array($reason, TldFacts::UNSUPPORTED, true)) {
            return 'This registrar '.$reason.'.';
        }
        return match ($reason) {
            'Seller account off.' => 'This registrar account is off. Turn it on in Servers or choose another account.',
            'Registrar price freshness unreadable.' => 'Check the price freshness setting on '.$account.'.',
            'Registrar account identity unreadable.' => 'Ask a site admin to check the registrar identity on '.$account.'.',
            'Registrar sales settings unreadable.' => 'Check the saved sales settings for this TLD on '.$account.'.',
            'Invalid domain product mapping.', 'Invalid or duplicate domain plan.', 'Duplicate domain price.', 'Invalid checkout price.' =>
                'Ask a site admin to check the checkout product, its plans and their USD prices.',
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
            'native_price_mismatch' => 'Checkout price differs. '.self::priceRecovery(),
            'native_mapping_unavailable' => 'Ask a site admin to check the checkout product, its plan and its USD price.',
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

    private static function priceRecovery(): string
    {
        $options = [];
        if (Staff::can(Review::permission('publish'))) {
            if (Staff::can(Review::permission('remove_override'))) { $options[] = 'Default pricing'; }
            if (Staff::can(Review::permission('override'))) { $options[] = 'Custom pricing'; }
        }
        return $options ? 'Review '.implode(' or ', $options).' to publish the checkout price.'
            : 'Ask staff with pricing access to publish the checkout price.';
    }

    public static function summary(array $rows): array
    {
        $counts = ['selling' => 0, 'checking' => 0, 'paused' => 0, 'ready' => 0, 'unchanged' => 0, 'blocked' => 0];
        foreach ($rows as $row) {
            $kind = $row['status'] !== 'ready' ? $row['status'] : match (self::saleState($row)) {
                State::ForSale => 'selling',
                State::Checking => 'checking',
                default => $row['orders_paused'] ? 'paused' : 'ready',
            };
            $counts[$kind]++;
        }
        return $counts;
    }

    public static function saleState(array $row): State
    {
        if ($row['status'] === 'blocked') {
            return $row['previous_sales'] === [] ? State::NotForSale : State::ForSale;
        }
        if (self::firstSale($row['prices']) !== null) {
            return State::ForSale;
        }
        foreach (array_diff_key($row['prices'], ['renew:1' => true]) as $price) {
            if (in_array($price['reason'], ['unknown_facts', 'stale_facts'], true)) {
                return State::Checking;
            }
        }
        return State::NotForSale;
    }

    public static function termsCheck(array $row): string
    {
        return ($row['facts_state'] ?? null) === 'stale'
            ? 'The registrar’s terms expired. Sales wait for a new check.'
            : 'The registrar’s terms have yet to be read. Sales wait for that check.';
    }

    public static function firstSale(array $prices): ?string
    {
        foreach ($prices as $term => $price) {
            if ($term !== 'renew:1' && $price['available']) {
                return $term;
            }
        }
        return null;
    }

    public static function termLabel(string $term): string
    {
        return self::ANNUAL[$term] ?? 'Register, '.substr($term, strlen('register:')).' years';
    }

    public static function missingDraft(array $row, array $price): string
    {
        return match (true) {
            $row['reason'] === 'Seller price missing.' => 'No price saved',
            $row['reason'] === 'Seller price unreadable.' => 'Saved price unreadable',
            ($price['reason'] ?? null) === 'unknown_price' => 'No cost read',
            ($price['reason'] ?? null) === 'provider_disabled' => 'Registrar does not offer this term',
            default => 'Not set',
        };
    }

    /** Compare prices offered at the two reviews of the same draft. A missing cost field can also mean access changed. */
    public static function changes(array $previous, array $current, bool $showCosts): array
    {
        $changes = [];
        foreach ($current as $suffix => $row) {
            $oldPrices = $previous[$suffix]['prices'] ?? [];
            $groups = [];
            foreach (self::ANNUAL + $oldPrices + $row['prices'] as $term => $_) {
                $old = $oldPrices[$term] ?? [];
                $new = $row['prices'][$term] ?? [];
                foreach (['after' => 'proposed price', 'cost' => 'cost'] as $field => $label) {
                    if ($field === 'cost' && (!$showCosts || !array_key_exists('cost', $old) || !array_key_exists('cost', $new))) {
                        continue;
                    }
                    $from = Fact::money($old[$field] ?? null)?->text ?? 'Not set';
                    $to = Fact::money($new[$field] ?? null)?->text ?? 'Not set';
                    if ($from !== $to) {
                        $key = $label.' '.$from.' to '.$to;
                        $groups[$key][] = self::termLabel($term);
                    }
                }
            }
            if ($groups !== []) {
                $changes[$suffix] = $groups;
            }
        }
        return $changes;
    }
}
