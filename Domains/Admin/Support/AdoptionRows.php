<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, ExpiryPrecision};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\Review;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\AdoptionRenewalEligibility;
/** The import preview as Detail sections: the domain, the last check's result and dates, then renewal and price. Reads
 * only what the page already holds (the record, the saved check and the reason typed for it); it queries nothing else
 * but the customer's name. */
final class AdoptionRows
{
    /** @param array<string,mixed> $review the saved check, as Review::jsonSerialize gives it
     *  @param ?string $environment test, live or null when unconfirmed */
    public static function sections(Domain $d, array $review, string $reason, ?string $environment, string $unavailable): array
    {
        $staff = Reader::Staff; $details = $review['details'] ?? []; $checked = ($review['code'] ?? null) !== null;
        $source = $details['source_expiry'] ?? ($d->renewal_policy['conversion_source_expiry'] ?? $d->provider_expires_at?->format('Y-m-d'));
        try { new DateEvidence($source ?? '', ExpiryPrecision::Date, 'conversion_source'); } catch (\Throwable) { $source = null; }
        $user = User::find($d->user_id);
        $account = match ($review['details']['environment'] ?? $environment) { 'test' => Value::plain(Plain::Test), 'live' => Value::plain(Plain::Live), default => Value::state(State::NotReadYet, $staff) };
        $customer = ($fact = $user === null ? null : Fact::attr($user, 'name')) === null ? Value::state(State::NotReadYet, $staff, 'Customer #'.$d->user_id) : Value::fact($fact, 'Customer #'.$d->user_id);
        $identity = [
            Row::notice('import', Value::state(State::NotSupported, $staff, $unavailable)),
            Row::make('Registrar account', $account),
            Row::make('Name', ($fact = Fact::host($d->domain) ?? Fact::attr($d, 'domain')) === null ? Value::state(State::NotReadYet, $staff) : Value::fact($fact)),
            Row::make('Customer', $customer),
            Row::make('Service ID', ($fact = Fact::attr($d, 'service_id')) === null ? Value::state(State::NotReadYet, $staff) : Value::fact($fact)),
        ];
        $result = match (true) {
            !$checked => Value::state(State::NotReadYet, $staff),
            $review['code'] === 'ready' => Value::state(State::Ok, $staff, ($details['test_only'] ?? false) ? 'Test only. No real domain is imported.' : (string) ($review['message'] ?? '')),
            in_array($review['code'], ['preview_failed', 'read_failed'], true) => Value::state(State::Failed, $staff, (string) ($review['message'] ?? '')),
            default => Value::state(State::NeedsReview, $staff, (string) ($review['message'] ?? '')),
        };
        $check = [
            Row::make('Result', $result),
            Row::make('Reason', ($fact = Fact::entered($reason)) === null ? Value::state(State::NotReadYet, $staff, 'Needed when you check the domain.') : Value::fact($fact)),
            Row::make('Expiry', self::when($details['expiry'] ?? null)),
            Row::make('Expiry on file', self::when($source)),
            Row::make('Renewal due', ($details['do_not_renew'] ?? false) ? Value::plain(Plain::None, 'Do not renew was requested.') : self::when($details['renewal_due'] ?? null)),
        ];
        $charged = isset($details['annual_charge']);
        $invoices = array_map(static fn ($i): string => 'Invoice #'.$i['invoice_id'].' ('.ucfirst((string) $i['status']).')', $details['history'] ?? []);
        $price = [
            Row::make('Renewal', self::renewal($details)),
            Row::make('Current annual charge', ($fact = $charged ? Fact::money((string) $details['annual_charge']) : null) === null ? Value::state(State::NotReadYet, $staff) : Value::fact($fact, 'Including tax.')),
            Row::make('Price review', !$charged ? Value::state(State::NotReadYet, $staff) : (($code = $details['price_notice'] ?? null) === null
                ? Value::state(State::Ok, $staff, Review::priceNotice(null)) : Value::state(State::NeedsReview, $staff, Review::priceNotice($code)))),
            Row::make('Past invoices', ($fact = Fact::lines($invoices)) === null ? Value::plain(Plain::None, 'No verified past invoices.') : Value::fact($fact)),
            Row::make('Do not renew', ($details['existing_do_not_renew'] ?? false) ? Value::plain(Plain::Yes, 'Already set to cancel at the end of the period. This check leaves that as is.')
                : (($details['do_not_renew'] ?? false) ? Value::plain(Plain::Yes, 'Requested for review. No cancellation was created.') : Value::plain(Plain::No))),
        ];
        $note = 'Preview only. Nothing is charged or imported, and past invoices stay the same.'.($environment === 'test' ? ' This is a test account. It cannot confirm a live domain.' : '');
        return [Section::make('Import is off on this site', $identity, $note), Section::make('Check', $check), Section::make('Renewal and price', $price)];
    }
    /** A calendar date stays a date and an instant shows in the viewer's zone. Anything else was not confirmed. */
    private static function when(?string $value): Value
    {
        $fact = null; $value = (string) $value;
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) { $fact = Fact::day($value); }
        elseif ($value !== '') { try { $fact = Fact::date((new \DateTimeImmutable($value))->getTimestamp(), Reader::Staff); } catch (\Throwable) {} }
        return $fact === null ? Value::state(State::NotReadYet, Reader::Staff) : Value::fact($fact);
    }
    private static function renewal(array $details): Value
    {
        return match (Review::renewalCase($details)) {
            'test' => Value::plain(Plain::Test, 'No invoice or charge is created.'),
            'off' => Value::plain(Plain::Off, 'The domain ends at its expiry date.'),
            'unproven' => Value::state(State::NotReadYet, Reader::Staff, AdoptionRenewalEligibility::STAFF),
            'unconfirmed' => Value::state(State::NotReadYet, Reader::Staff, 'Run Check to see if this domain can renew here.'),
            default => Value::plain(Plain::On, 'Normal invoices apply. If the domain is already due, an invoice may go out right away.'),
        };
    }
}
