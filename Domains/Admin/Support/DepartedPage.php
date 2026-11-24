<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Reader,StaffTime,State};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingDeparture,ManagementNameservers};
/** The staff page of a confirmed departure (mock 07), read from its saved record and the confirmation's history entry
 * only. The suspected review's sections, notices and steps no longer apply, so the page shows Departure, Registration and
 * Billing. */
final class DepartedPage
{
    public static function record(Domain $d): ?array
    {
        try { $r = $d->status === 'departed' ? ($d->renewal_policy['departure'] ?? null) : null; } catch (\Throwable) { return null; }
        return is_array($r) && isset(HoldingDeparture::REASONS[$r['reason'] ?? '']) ? $r : null;
    }
    /** The head's reason sentence and its "Confirmed by" line. @return array{0:string,1:?string} */
    public static function head(Domain $d, array $r): array
    {
        $at = self::time($r['confirmed_at'] ?? null);
        return [HoldingDeparture::REASONS[$r['reason']], $at === null ? null : 'Confirmed by '.self::staff($r).', '.StaffTime::at($at).'.'];
    }
    /** @param list<Row> $billing the shipped Customer row, then the Service row's link */
    /** The Open alerts row of a departed page: every live alert but an account hold with its cause, a failed customer notice, and the reminder
     * check. Confirmation resolved only its departure review and stopped-sync alerts. Null when nothing is open. */
    public static function alerts(Domain $d, bool $reminderCheck): ?array
    {
        // An open account hold is the Billing notice, with Release hold, so Open alerts does not repeat it.
        $codes = array_values(array_diff(array_unique(array_column(StaffDomainInputs::for([$d])[$d->id]['alerts'] ?? [], 'code')), ['account_money_hold']));
        $causes = array_map(fn (string $code) => DomainFacts::alertCause($code, $d), $codes);
        $tone = array_filter($codes, fn (string $code) => (DomainFacts::ALERTS[$code][1] ?? 'warning') === 'danger') ? 'danger' : 'warning';
        if ($reminderCheck && $causes === []) { $causes[] = 'A reminder needs a check.'; }
        return $causes === [] ? null : ['value' => array_values(array_unique($causes)), 'sub' => null, 'url' => null, 'tone' => $tone, 'help' => null, 'demo' => false];
    }
    /** @param ?Row $alerts the Open alerts row, first in Registration as on the review page
     * @param ?Row $hold the open account hold notice, first in Billing as on the review page */
    public static function sections(Domain $d, array $r, ?Row $customer, ?string $serviceUrl, ?Row $alerts = null, ?Row $hold = null): array
    {
        $entry = DepartedHistory::entry($d); $at = self::time($r['confirmed_at'] ?? null);
        $registry = is_array($r['registry'] ?? null) ? $r['registry'] : [];
        $listed = is_string($registry['registrar'] ?? null) && ($registry['at'] ?? null) !== null
            ? 'The public registry listed '.$registry['registrar'].' on '.StaffTime::at(is_int($registry['at']) ? $registry['at'] : (int) strtotime((string) $registry['at'])).'.' : null;
        $rows = [Row::make('Reason', Value::fact(Fact::entered(rtrim(HoldingDeparture::REASONS[$r['reason']], '.')), $listed))];
        if ($at !== null) { $rows[] = Row::make('Confirmed', Value::fact(Fact::entered(self::staff($r)), StaffTime::at($at))); }
        if (is_string($r['note'] ?? null) && trim($r['note']) !== '') { $rows[] = Row::make('Staff note', Value::fact(Fact::entered($r['note']))); }
        foreach (is_array($r['invoices'] ?? null) ? $r['invoices'] : [] as $o) {
            if (!is_array($o) || !is_int($o['invoice_id'] ?? null)) { continue; }
            $name = DepartureConfirm::invoice($o['invoice_id']);
            $line = match ($o['outcome'] ?? null) {
                'cancelled_unpaid' => $name.', unpaid renewal'.(($t = (string) \App\Models\Invoice::find($o['invoice_id'])?->formattedTotal) !== '' ? ', '.$t : '').'.',
                'credited' => $name.', paid renewal, never sent to the registrar. '.DepartureConfirm::money((int) ($o['credit_minor'] ?? 0)).' to account credit.',
                default => null };
            if ($line !== null) { $rows[] = Row::make('Renewal', Value::state(State::Cancelled, Reader::Staff, $line))->actions(Sheet::link('departed_invoice_'.$o['invoice_id'], 'Open invoice', Facts::invoiceUrl($o['invoice_id']))); }
        }
        $rows[] = Row::make('Name', Value::fact(Fact::entered('Released'), 'Available to order. A new order gets its own record.'));
        $rows[] = Row::make('Service', Value::state(State::Cancelled, Reader::Staff, 'Service #'.$d->service_id.'.'));
        $registrar = ManagementNameservers::registrar($d);
        $product = match ($d->registrar_key) { 'enom' => 'Enom DNS', 'namesilo' => 'NameSilo DNSOwl', default => $registrar.' DNS' };
        $dns = match ($r['dns_case'] ?? null) {
            'registrar' => Value::fact(Fact::entered("Our registrar's DNS"), $product.', from the last registrar read. '.$registrar.' stopped serving it when the transfer completed.'),
            'other' => Value::fact(Fact::entered('Other nameservers'), 'From the last registrar read. The transfer does not change them.'),
            default => null };
        if ($dns !== null) { $rows[] = Row::make('DNS records', $dns); }
        if ($email = DepartedHistory::email($d, $entry)) {
            $owner = $d->user; $name = $owner ? (trim($owner->first_name.' '.$owner->last_name) ?: (string) $owner->email) : $email['to'];
            $rows[] = Row::make('Email', Value::fact(Fact::entered('Sent to '.$name.', '.StaffTime::at($email['at'])), 'Subject “'.$email['subject'].'”.'));
        }
        $registration = $alerts ? [$alerts] : []; $us = \Paymenter\Extensions\Servers\Domains\Dns\CompanyLabels::company() ?: 'us';
        $from = $d->created_at; $zone = new \DateTimeZone(config('domains.time_zone') ?: config('app.timezone'));
        if ($from !== null && $at !== null) { $registration[] = Row::make('With '.$us, Value::fact(Fact::entered(\Carbon\Carbon::parse($from)->setTimezone($zone)->format('M j, Y').' to '.(new \DateTimeImmutable('@'.$at))->setTimezone($zone)->format('M j, Y')))); }
        if ($d->provider_expires_at !== null) { $registration[] = Row::make('Last known expiry', Value::fact(Fact::entered($d->provider_expires_at->format('M j, Y')), 'From the last registrar read.')); }
        $account = (string) ($d->server?->name ?? '');
        $registration[] = Row::make('Registrar with '.$us, Value::fact(Fact::entered($registrar), $account !== '' && !Fact::isWord($account) ? $account : null));
        $service = Row::make('Service', Value::state(State::Cancelled, Reader::Staff, $d->domain.' registration, Service #'.$d->service_id.'.'))
            ->actions(Sheet::link('departed_service', 'Open service', $serviceUrl));
        return [Section::make('Departure', $rows), Section::make('Registration', $registration), Section::make('Billing', [...($hold ? [$hold] : []), ...($customer ? [$customer] : []), $service])];
    }
    private static function staff(array $r): string
    {
        $u = is_int($r['actor_id'] ?? null) ? User::find($r['actor_id']) : null;
        return $u ? (trim($u->first_name.' '.$u->last_name) ?: 'Staff') : 'Staff';
    }
    private static function time(mixed $v): ?int
    { return is_string($v) && ($t = strtotime($v)) !== false ? $t : null; }
}
