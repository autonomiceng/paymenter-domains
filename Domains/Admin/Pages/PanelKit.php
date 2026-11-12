<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\{Component, Html, Section};
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\{DB, Schema as Tables};
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ReminderAction, Sheet, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, SettingsRow, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};
use Paymenter\Extensions\Servers\Domains\Presentation\Codes\InvoiceStatus;
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The approved M0 panel kit drawn by the real pieces (Head, Value, Row, SettingsRow, Trace), so a skin can be checked
 * against the mock. Demo systems only: it opens while a seeded demo registrar account exists, and it is not in the menu.
 * Its data is made up and its buttons run nothing, except Edit reminders, which is the account page's own action on the
 * demo account. */
class PanelKit extends Page
{
    protected static ?string $slug = 'panel-kit';
    protected static bool $shouldRegisterNavigation = false;
    protected Width|string|null $maxContentWidth = Sheet::WIDTH;
    #[Locked] public int $server = 0;
    public static function canAccess(): bool { return Staff::can() && DemoData::present(); }
    public function mount(): void
    {
        Staff::requireView();
        $server = Account::query()->whereIn('registrar_key', array_keys(DemoData::ACCOUNTS))->get()->first(static fn (Account $a): bool => DemoData::is($a))?->server_id;
        abort_if($server === null, 404);
        $this->server = (int) $server;
    }
    public function getTitle(): string { return 'Panel kit'; }
    private function head(): Head
    {
        return Head::make('fieldnote.dev', Reader::Staff)->domain()->tag(true, false)->state(State::Active, 'kit-domain')
            ->fact('Expires', Fact::day('2027-03-04'))->link('Enom TEST', '#kit-rows');
    }
    public function getHeading(): Htmlable { return $this->head()->heading(); }
    public function getSubheading(): ?Htmlable { return $this->head()->line(); }
    protected function getHeaderActions(): array { return [self::kit('renew', 'Renew')]; }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // A save changes what the rows show. Rebuild them, as the account page does, so the next change reads the new revision.
        unset($this->cachedSchemas['content']);$this->cachedActions = [];$this->forceRender();
    }

    public function content(Schema $schema): Schema
    {
        Staff::requireView();
        $staff = Reader::Staff;
        return $schema->components([
            Sheet::split([
                Sheet::section('Tones and mark sizes', [
                    Row::make('OK', Value::state(State::Active, $staff)), Row::make('Off', Value::state(State::Paused, $staff)),
                    Row::make('In progress', Value::state(State::Running, $staff)), Row::make('Needs attention', Value::state(State::NeedsReview, $staff)),
                    Row::make('Failed', Value::state(State::Failed, $staff)),
                ], 'Five tones, one hexagon each: the head above, a row here, the rail beside. The tone picks the mark.', 'kit-marks'),
            ], [
                Sheet::section('Activity', [Trace::make($staff)
                    ->step('Renewal sent to Enom TEST', State::Checking, self::AT, null, null, 'kit-renewal')
                    ->step('Invoice 100', State::Paid, self::AT - 60, 'the customer')
                    ->step('Registrar read', State::CouldntRead, self::AT - 4320, null, 'We couldn\'t reach the registrar.')
                    ->step('Auto-renew at registrar', State::Stopped, self::AT - 950400, 'Ilya')], null, 'kit-activity'),
            ], 'kit-split'),
            Sheet::single([
                ...array_map(static fn (string $tone, array $states): Section => Sheet::section($tone, [self::words($states)], null, 'kit-words-'.strtolower(str_replace(' ', '-', $tone))), array_keys(self::WORDS), self::WORDS),
                Sheet::section('One word for both readers', [
                    Row::make('Staff', Value::state(State::UnderReview, $staff, 'This paid change waits for staff review.'))->actions(Sheet::link('kit_request', 'Check request #84', '#kit-words')),
                    Row::make('Customer', Value::state(State::UnderReview, Reader::Customer, 'This paid change waits for staff review.')),
                    Row::make('Staff', Value::state(State::AwaitingPayment, $staff, 'Invoice 100 is due Nov 19, 2026.'))->key('row-staff-pay')->actions(Sheet::link('kit_invoice', 'Open Invoice 100', '#kit-words')),
                    Row::make('Customer', Value::state(State::AwaitingPayment, Reader::Customer, 'Invoice 100 is due Nov 19, 2026.'))->key('row-customer-pay')->actions(Sheet::link('kit_pay', 'Pay Invoice 100', '#kit-words')),
                    Row::make('Staff', Value::state(State::CouldntRead, $staff, 'We couldn\'t reach the registrar.')->when(self::AT)->detail(Fact::code('2400')))->key('row-staff-read'),
                    Row::make('Customer', Value::state(State::CouldntRead, Reader::Customer, 'We couldn\'t reach the registrar.')->when(self::AT)->detail(Fact::code('2400')))->key('row-customer-read'),
                ], 'The word and its reason never change with the reader. The tone follows who can act.', 'kit-readers'),
            ], 'kit-words'),
            Sheet::single([
                Sheet::section('A state', [
                    Row::make('Status', Value::state(State::Active, $staff)),
                    Row::make('Last backup', Value::state(State::Failed, $staff, 'The upload stopped at 40%.')->when(self::AT + 17280))->actions(self::kit('retry', 'Try again')),
                    Row::make('Invoice status', Value::coded(InvoiceStatus::state('STATUS_77', null, '2026-11-12'), $staff)),
                ], null, 'kit-value-state'),
                Sheet::section('A plain word', [
                    SettingsRow::setting('Registrar auto-renew', Value::plain(Plain::Off), 'Set by Beco and kept off.')->help('Beco renews the domain after the invoice is paid, so the customer is never charged twice.'),
                    SettingsRow::setting('Price sync', Value::plain(Plain::Daily), 'Set by Beco.')->help('Prices sync from the registrar once a day.'),
                    SettingsRow::setting('Registry lock', Value::plain(Plain::NotApplicable), 'Set by the registry.')->help('The .dev registry has no registry lock.'),
                    SettingsRow::setting('New domains use', Value::plain(Plain::Default, 'ns1.beco.dev and ns2.beco.dev. Used by 597 TLDs.'), 'Set by staff in domain settings.')->actions(Sheet::link('kit_ns', 'Change', '#kit-value')),
                ], null, 'kit-value-plain'),
                Sheet::section('A fact', [
                    Row::make('Balance', Value::fact(Fact::money('1240.00'))),
                    Row::make('Domains', Value::fact(Fact::count(9), '3 renew in November.')),
                    Row::make('TLDs for sale', Value::fact(Fact::tlds(['com', 'net', 'org', ...array_map(static fn (int $i): string => 'kit'.$i, range(1, 596))]))),
                    Row::make('Last read', Value::fact(Fact::date(self::AT, $staff))),
                    Row::make('Expires for staff', Value::fact(Fact::date(self::EXPIRES, $staff))),
                    Row::make('Expires for customers', Value::fact(Fact::date(self::EXPIRES, Reader::Customer))),
                ], 'All amounts in USD.', 'kit-value-fact'),
            ], 'kit-value'),
            Sheet::single([
                Sheet::section('From the registrar', [
                    Row::make('Transfer lock', Value::state(State::NotReadYet, $staff, 'A read is scheduled.')->when(self::AT + 17280)),
                    Row::make('Nameservers', Value::state(State::Checking, $staff, 'Reading from the registrar.')),
                    Row::make('Contacts', Value::state(State::CouldntRead, $staff, 'We couldn\'t reach the registrar.')->when(self::AT))->actions(self::kit('check', 'Check now')),
                    Row::make('WHOIS privacy', Value::state(State::NotForSale, $staff, 'Enom TEST doesn\'t offer privacy for .dev.')),
                    Row::make('DNSSEC', Value::state(State::NotSupported, $staff, 'Enom TEST can\'t change DS records for .dev.')),
                ], null, 'kit-availability-read'),
                Sheet::section('Domain defaults', [
                    SettingsRow::setting('Default registrar', Value::state(State::NotSet, $staff, 'New registrations need a registrar.'), 'Set by staff in domain settings.')->actions(Sheet::link('kit_default', 'Change', '#kit-availability')),
                ], null, 'kit-availability-set'),
            ], 'kit-availability'),
            Sheet::split([
                Sheet::section('Domain', [
                    Row::make('Renewal', Value::state(State::Checking, $staff, 'Renewing for 1 year at Enom TEST.')),
                    Row::make('Transfer', Value::state(State::UnderReview, $staff, 'This paid change waits for staff review.'))->actions(Sheet::link('kit_transfer', 'Check request #84', '#kit-rows')),
                    Row::make('Nameservers', Value::fact(Fact::names(['ns1.beco.dev', 'ns2.beco.dev'])))->actions(self::kit('ns_edit', 'Edit', 'Nameservers')),
                    Row::make('Transfer lock', Value::plain(Plain::On, 'Turning off at the registrar. The button comes back when this finishes.'))->running(Value::state(State::Checking, $staff)),
                ], null, 'kit-rows-domain'),
                Sheet::section('Registrar account', [
                    Row::make('Balance', Value::fact(Fact::money('1240.00')))->actions(self::kit('balance', 'Check balance'), Sheet::link('kit_funds', 'Add funds', '#kit-rows')),
                    SettingsRow::toggle('Renewal invoices', true, self::kit('invoices', 'Renewal invoices'), 'Set by staff on the registrar account.', 'Beco sends a renewal invoice 30 days before a domain expires.'),
                    SettingsRow::setting('Mode', Value::plain(Plain::Test), 'Set by staff when the account is connected.')->help('Test and Live are separate registrar accounts, each with its own login, domains and money.'),
                    SettingsRow::setting('Reminder schedule', $this->reminderDays(), 'Set by staff who can change domain policy.')->actions(Sheet::edit(ReminderAction::make($this->server), 'Reminder schedule')),
                ], 'All amounts in USD.', 'kit-rows-account'),
            ], [
                Sheet::section('Account', [
                    Row::make('Domains', Value::fact(Fact::count(9))),
                    Row::make('Last sync', Value::fact(Fact::date(self::AT, $staff))),
                ], null, 'kit-rail-account'),
            ], 'kit-rows'),
            Sheet::single([
                Sheet::section('Orders', [
                    SettingsRow::toggle('Registrations', true, self::kit('registrations', 'Registrations'), 'Set by staff on the registrar account.'),
                    SettingsRow::toggle('Renewals', false, self::kit('renewals', 'Renewals'), 'Set by staff on the registrar account.'),
                    SettingsRow::toggle('Transfers', false, self::kit('transfers', 'Transfers'), 'Set by staff on the registrar account.')
                        ->blocked('Transfers can\'t be turned on without a Live registrar account.', Sheet::link('kit_live', 'Connect a Live account', '#kit-toggle')),
                ], null, 'kit-toggle-orders'),
                Sheet::section('Payment', [
                    SettingsRow::setting('Auto Pay', Value::plain(Plain::On), 'Set by the customer on their payment page.'),
                ], null, 'kit-toggle-payment'),
                Sheet::section('Domain lock', [
                    Row::make('Transfer lock', Value::plain(Plain::On))->actions(self::kit('lock', 'Turn off', 'Transfer lock')),
                ], null, 'kit-toggle-lock'),
            ], 'kit-toggle'),
            Sheet::single([
                Sheet::section('Registrar', [
                    $paused = Row::notice('orders-paused', Value::state(State::OrdersPaused, $staff, 'Changes can\'t be sent to Enom TEST while orders are paused.'), Sheet::link('kit_orders', 'Open order settings', '#kit-toggle')),
                    Row::make('Last sync', Value::fact(Fact::date(self::AT, $staff)))->actions(self::kit('sync', 'Sync now')),
                    Row::make('Nameservers', Value::fact(Fact::names(['ns1.beco.dev', 'ns2.beco.dev'])))->key('row-blocked-ns')->actions(self::kit('blocked_ns', 'Edit', 'Nameservers'))->blockedBy($paused),
                    Row::make('Transfer lock', Value::plain(Plain::On))->key('row-blocked-lock')->actions(self::kit('blocked_lock', 'Turn off', 'Transfer lock'))->blockedBy($paused),
                ], null, 'kit-blocked-registrar'),
                Sheet::section('DNS', [
                    SettingsRow::toggle('DNS hosting', false, self::kit('dns', 'DNS hosting'), 'Set by staff on the domain.'),
                    Row::make('Host records', Value::fact(Fact::count(3)))->actions(self::kit('records', 'Edit', 'Host records'))
                        ->blocked('Records can\'t be edited while DNS hosting is off. Turn on DNS hosting to edit them.'),
                ], null, 'kit-blocked-dns'),
                Sheet::section('Domain', [
                    Row::make('Domain', Value::fact(Fact::host('fieldnote.dev')))->actions(self::kit('delete', 'Delete', 'fieldnote.dev')->color('danger'))
                        ->blocked('This domain can\'t be deleted while the renewal is running. Delete works again when the renewal finishes.'),
                ], null, 'kit-blocked-domain'),
            ], 'kit-blocked'),
            Sheet::single([
                Sheet::section('Before', [Row::make('Transfer lock', Value::plain(Plain::On))->key('row-live-before')->actions(self::kit('live_before', 'Turn off', 'Transfer lock'))], null, 'kit-live-before'),
                Sheet::section('Sent', [Row::make('Transfer lock', Value::plain(Plain::On, 'Turning off at the registrar. The button comes back when this finishes.'))->key('row-live-sent')->running(Value::state(State::Checking, $staff))], null, 'kit-live-sent'),
                Sheet::section('After 30 seconds', [Row::make('Transfer lock', Value::plain(Plain::On, 'This is taking longer than usual. We\'ll keep trying.'))->key('row-live-slow')->running(Value::state(State::Checking, $staff))], null, 'kit-live-slow'),
                Sheet::section('Done', [Row::make('Transfer lock', Value::plain(Plain::Off))->key('row-live-done')->result(Value::state(State::Completed, $staff, 'Turned off and confirmed at the registrar.')->when(self::AT + 120))->actions(self::kit('live_done', 'Turn on', 'Transfer lock'))], null, 'kit-live-done'),
                Sheet::section('Failed', [Row::make('Transfer lock', Value::plain(Plain::On))->key('row-live-failed')->result(Value::state(State::Failed, $staff, 'The registrar didn\'t answer. Nothing changed.'))->actions(self::kit('live_retry', 'Try again', 'Transfer lock'))], null, 'kit-live-failed'),
            ], 'kit-live'),
            Sheet::single([
                Sheet::section('From the registrar', [
                    Row::notice('read-failed', Value::state(State::CouldntRead, $staff, 'We couldn\'t reach the registrar.')->when(self::AT), self::kit('notice_sync', 'Sync now')),
                    Row::make('Transfer lock', Value::state(State::CouldntRead, $staff))->key('row-notice-lock'),
                    Row::make('Nameservers', Value::state(State::CouldntRead, $staff))->key('row-notice-ns'),
                    Row::make('Contacts', Value::state(State::CouldntRead, $staff))->key('row-notice-contacts'),
                ], null, 'kit-notice-read'),
                Sheet::section('Registrar account', [
                    Row::notice('low-balance', Value::state(State::LowBalance, $staff, 'Renewals due by Nov 24, 2026 need $41.00. Renewals stop below $25.00.'), Sheet::link('kit_low_funds', 'Add funds', '#kit-notice')),
                    Row::make('Balance', Value::fact(Fact::money('42.10')))->key('row-notice-balance')->actions(self::kit('notice_balance', 'Check balance')),
                ], 'All amounts in USD.', 'kit-notice-account'),
            ], 'kit-notice'),
        ]);
    }
    /** Nov 12, 2026, 17:12 UTC, the kit's "now". */
    private const AT = 1794503520;
    private const EXPIRES = 1804180320;
    private const WORDS = [
        'OK' => [State::Ok, State::Active, State::Monitored, State::Completed, State::Paid, State::ForSale],
        'Off' => [State::NotReadYet, State::NotForSale, State::NotSupported, State::NotConnected, State::Paused, State::Stopped, State::Cancelled, State::NotRecognized, State::Unpaid],
        'In progress' => [State::Checking, State::Queued, State::Running, State::PendingRegistration, State::PendingTransfer, State::AwaitingPayment],
        'Needs attention' => [State::NotSet, State::NeedsReview, State::ActionRequired, State::OrdersPaused, State::ExpiresToday, State::LowBalance, State::Delayed, State::OutOfDate, State::UnderReview],
        'Failed' => [State::CouldntRead, State::Failed, State::ConnectionFailed, State::Expired, State::Redemption],
    ];
    /** Every word of one tone, as staff read it. @param list<State> $states */
    private static function words(array $states): Component
    { return Html::make(new HtmlString('<div class="beco-kit-words">'.implode('', array_map(static fn (State $s): string => Value::state($s, Reader::Staff)->toHtml(), $states)).'</div>')); }
    /** The demo account's saved reminder days, read the way the account page reads them. */
    private function reminderDays(): Value
    {
        $row = Tables::hasTable('domains_reminder_schedules') ? DB::table('domains_reminder_schedules')->where('server_id', $this->server)->first() : null;
        if ($row === null) { return Value::state(State::NotReadYet, Reader::Staff); }
        $before = ReminderSchedules::storedDays($row->before_days);$after = ReminderSchedules::storedDays($row->after_days);
        if ($before === null || $after === null) { return Value::state(State::CouldntRead, Reader::Staff, 'Saved reminder days can\'t be read.'); }
        rsort($before, SORT_NUMERIC);sort($after, SORT_NUMERIC);
        $days = Fact::days($before, $after);
        return $days === null ? Value::plain(Plain::None) : Value::fact($days);
    }
    /** A kit button: a native action that runs nothing. $row names the row for screen readers ("Edit Nameservers"). */
    private static function kit(string $name, string $label, ?string $row = null): Action
    {
        $action = Sheet::button(Action::make('kit_'.$name)->label($label)->action(static fn (): null => null));
        return $row === null ? $action : $action->extraAttributes(['aria-label' => $label.' '.$row]);
    }
}
