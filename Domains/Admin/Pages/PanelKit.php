<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
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
class PanelKit extends Page implements \Filament\Tables\Contracts\HasTable
{
    use \Filament\Tables\Concerns\InteractsWithTable;
    protected static ?string $slug = 'panel-kit';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
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
    public function getSubNavigation(): array { return self::tabs('Pieces'); }
    /** The kit's tabs: every piece, then one example page of each page type. @return list<NavigationItem> */
    public static function tabs(string $active): array
    {
        $items = [];
        // A page the panel never registered drops out, as on the other staff tabs.
        foreach (self::TABS as $label => $page) {
            try { $items[] = NavigationItem::make($label)->url($page::getUrl())->isActiveWhen(static fn (): bool => $label === $active); } catch (\Throwable) { continue; }
        }
        return count($items) > 1 ? $items : [];
    }
    private const TABS = ['Pieces' => self::class, 'Detail' => PanelKitDetail::class, 'Settings' => PanelKitSettings::class, 'List' => PanelKitList::class, 'Record list' => PanelKitRecords::class, 'History' => PanelKitHistory::class];
    private function head(): Head
    {
        return Head::make('fieldnote.example', Reader::Staff)->domain()->tag(true, false)->state(State::Active, 'kit-domain')
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
                    ->step('Check the renewal price', State::NeedsReview, self::AT - 8000, null, 'Compare the renewal price with the current registrar cost.')
                    ->step('Monitoring paused', State::Paused, self::AT - 86400, 'Ilya')
                    ->step('Auto-renew at registrar', State::Stopped, self::AT - 950400, 'Ilya')], null, 'kit-activity', [Sheet::link('kit_all_activity', 'History', PanelKitHistory::getUrl())]),
            ], 'kit-split'),
            Sheet::single([
                Sheet::section('A failed record', [Html::make(Head::make('fieldnote.example', $staff)->state(State::Failed, 'kit-failed-record')->line())], null, 'kit-failed-head'),
                Sheet::section('A review', [Html::make(Head::make('fieldnote.example', $staff)->state(State::NeedsReview, 'kit-review-record')->line())], null, 'kit-review-head'),
                Sheet::section('An unpaid invoice', [Html::make(Head::make('Invoice 100', $staff)->state(State::Unpaid, 'kit-unpaid-record')->fact('Balance', Fact::entered('$120.00 USD'))->line())], null, 'kit-unpaid-head'),
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
                    SettingsRow::setting('Registrar auto-renew', Value::plain(Plain::Off), 'Set by the host and kept off.')->help('The domain renews after the invoice is paid, so the customer is never charged twice.'),
                    SettingsRow::setting('Price sync', Value::plain(Plain::Daily), 'Set by the host.')->help('Prices sync from the registrar once a day.'),
                    SettingsRow::setting('Registry lock', Value::plain(Plain::NotApplicable), 'Set by the registry.')->help('The .dev registry has no registry lock.'),
                    SettingsRow::setting('New domains use', Value::plain(Plain::Default, 'ns1.example.net and ns2.example.net. Used by 597 TLDs.'), 'Set by staff in domain settings.')->actions(Sheet::link('kit_ns', 'Change', '#kit-value')),
                ], null, 'kit-value-plain'),
                Sheet::section('A fact', [
                    Row::make('Balance', Value::fact(Fact::money('1240.00'))),
                    Row::make('Domains', Value::fact(Fact::count(9), '3 renew in November.')),
                    Row::make('Version', Value::fact(Fact::entered('1.5.9'))),
                    Row::make('TLDs for sale', Value::fact(Fact::tlds(['com', 'net', 'org', ...array_map(static fn (int $i): string => 'kit'.$i, range(1, 596))]))),
                    Row::make('Last read', Value::fact(Fact::date(self::AT, $staff))),
                    Row::make('Nameservers', Value::fact(Fact::lines(['ns1.example.net', 'ns2.example.net'])))->help('Nameservers answer DNS queries for this domain.'),
                    Row::make('Registrar response', Value::state(State::CouldntRead, $staff, 'The registrar did not answer.')->detail(Fact::code('2400'))),
                    Row::make('Read errors', Value::fact(Fact::lines(['The registrar did not answer.', 'The saved balance could not be read.']))),
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
                    Row::make('Nameservers', Value::fact(Fact::names(['ns1.example.net', 'ns2.example.net'])))->actions(self::kit('ns_edit', 'Edit', 'Nameservers')),
                    Row::make('Transfer lock', Value::plain(Plain::On, 'Turning off at the registrar. The button comes back when this finishes.'))->running(Value::state(State::Checking, $staff)),
                ], null, 'kit-rows-domain'),
                Sheet::section('Registrar account', [
                    Row::make('Balance', Value::fact(Fact::money('1240.00')))->actions(self::kit('balance', 'Check balance'), Sheet::link('kit_funds', 'Add funds', '#kit-rows')),
                    SettingsRow::toggle('Auto-renew', true, self::kit('invoices', 'Auto-renew'), 'Set by staff on the registrar account.', 'Paid by an invoice we email 30 days before expiry.'),
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
                    SettingsRow::setting('Auto-renew', Value::plain(Plain::On, 'Paid by Auto Pay. We charge Visa 4242 $10.99 on Oct 14, 2027.'), 'Set by the customer on their domain page.'),
                ], null, 'kit-toggle-payment'),
                Sheet::section('Domain lock', [
                    Row::make('Transfer lock', Value::plain(Plain::On))->actions(self::kit('lock', 'Turn off', 'Transfer lock')),
                ], null, 'kit-toggle-lock'),
            ], 'kit-toggle'),
            Sheet::single([
                Sheet::section('Registrar', [
                    $paused = Row::notice('orders-paused', Value::state(State::OrdersPaused, $staff, 'Changes can\'t be sent to Enom TEST while orders are paused.'), Sheet::link('kit_orders', 'Open order settings', '#kit-toggle')),
                    Row::make('Last sync', Value::fact(Fact::date(self::AT, $staff)))->actions(self::kit('sync', 'Sync now')),
                    Row::make('Nameservers', Value::fact(Fact::names(['ns1.example.net', 'ns2.example.net'])))->key('row-blocked-ns')->actions(self::kit('blocked_ns', 'Edit', 'Nameservers'))->blockedBy($paused),
                    Row::make('Transfer lock', Value::plain(Plain::On))->key('row-blocked-lock')->actions(self::kit('blocked_lock', 'Turn off', 'Transfer lock'))->blockedBy($paused),
                ], null, 'kit-blocked-registrar'),
                Sheet::section('DNS', [
                    SettingsRow::toggle('DNS hosting', false, self::kit('dns', 'DNS hosting'), 'Set by staff on the domain.'),
                    Row::make('Host records', Value::fact(Fact::count(3)))->actions(self::kit('records', 'Edit', 'Host records'))
                        ->blocked('Records can\'t be edited while DNS hosting is off. Turn on DNS hosting to edit them.'),
                ], null, 'kit-blocked-dns'),
                Sheet::section('Domain', [
                    Row::make('Domain', Value::fact(Fact::host('fieldnote.example')))->actions(self::kit('delete', 'Delete', 'fieldnote.example')->color('danger'))
                        ->blocked('This domain can\'t be deleted while the renewal is running. Delete works again when the renewal finishes.'),
                ], null, 'kit-blocked-domain'),
            ], 'kit-blocked'),
            Sheet::single([
                Sheet::section('Before', [Row::make('Transfer lock', Value::plain(Plain::On))->key('row-live-before')->actions(self::kit('live_before', 'Turn off', 'Transfer lock'))], null, 'kit-live-before'),
                Sheet::section('Sent', [Row::make('Transfer lock', Value::plain(Plain::On, 'Turning off at the registrar. The button comes back when this finishes.'))->key('row-live-sent')->running(Value::state(State::Checking, $staff))], null, 'kit-live-sent'),
                Sheet::section('After 30 seconds', [Row::make('Transfer lock', Value::plain(Plain::On, 'This is taking longer than usual. We\'ll keep trying.'))->key('row-live-slow')->running(Value::state(State::Checking, $staff))], null, 'kit-live-slow'),
                Sheet::section('Past its normal time, for staff', [Row::make('Transfer lock', Value::plain(Plain::On, 'The registrar has not confirmed this change. Check request #84 before sending another.'))->key('row-live-delayed')->running(Value::state(State::Delayed, $staff))], null, 'kit-live-delayed'),
                Sheet::section('Done', [Row::make('Transfer lock', Value::plain(Plain::Off, 'Turned off. Confirmed at the registrar', Fact::date(self::AT + 120, $staff)))->key('row-live-done')->actions(self::kit('live_done', 'Turn on', 'Transfer lock'))], null, 'kit-live-done'),
                Sheet::section('Failed', [Row::make('Transfer lock', Value::plain(Plain::On))->key('row-live-failed')->result(Value::state(State::Failed, $staff, 'The registrar didn\'t answer. Nothing changed.'))->actions(self::kit('live_retry', 'Try again', 'Transfer lock'))], null, 'kit-live-failed'),
            ], 'kit-live'),
            Sheet::single([
                Sheet::section('Updating', [Row::notice('kit-update-guide', Value::state(State::NeedsReview, $staff, 'See the updating guide for how to update.'), Sheet::link('kit_update_guide', 'Updating guide', 'https://example.com/updating', true))], null, 'kit-update-guide'),
                Sheet::section('From the registrar', [
                    Row::notice('read-failed', Value::state(State::CouldntRead, $staff, 'We couldn\'t reach the registrar.')->when(self::AT), self::kit('notice_sync', 'Sync now')),
                    Row::make('Transfer lock', Value::plain(Plain::On))->key('row-notice-lock'),
                    Row::make('Nameservers', Value::fact(Fact::lines(['ns1.example.net', 'ns2.example.net'])))->key('row-notice-ns'),
                    Row::make('Contacts', Value::state(State::CouldntRead, $staff))->key('row-notice-contacts'),
                ], null, 'kit-notice-read'),
                Sheet::section('Registrar account', [
                    Row::notice('low-balance', Value::state(State::LowBalance, $staff, 'Renewals due by Nov 24, 2026 need $41.00. Renewals stop below $25.00.'), Sheet::link('kit_low_funds', 'Add funds', '#kit-notice')),
                    Row::make('Balance', Value::fact(Fact::money('42.10')))->key('row-notice-balance')->actions(self::kit('notice_balance', 'Check balance')),
                ], 'All amounts in USD.', 'kit-notice-account'),
            ], 'kit-notice'),
            \Filament\Schemas\Components\EmbeddedTable::make(),
        ]);
    }
    /** Native table variants: Columns and Reset, attention notes, inline actions, currency codes and two pages. */
    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        $c = \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::class;
        $columns = [
            $c::domain('domain', 'Domain')->searchable(),
            $c::value('status', 'Status', static fn (array $r): Value => Value::state(State::from($r['status']), Reader::Staff)),
            $c::note('note', 'Note', static fn (array $r): ?string => $r['note'])->actWhen(static fn (array $r): bool => $r['status'] === 'needs_review'),
            $c::text('customer', 'Customer'),
            $c::value('amount', 'Renewal price', static fn (array $r): Value => Value::fact(Fact::money($r['amount'], $r['currency'])))->end(),
            $c::value('nameservers', 'Nameservers', static fn (array $r): Value => Value::fact(Fact::lines(['ns1.example.net', 'ns2.example.net'])))->optional(),
        ];
        return \Paymenter\Extensions\Servers\Domains\Admin\Support\TableColumns::choose($table, array_map(static fn ($c) => $c->toColumn(), $columns), ['nameservers'])
            ->records(static function (?string $search, array $filters, int $page, int $recordsPerPage): \Illuminate\Pagination\LengthAwarePaginator {
                $rows = collect(range(1, 28))->mapWithKeys(static fn (int $n): array => [$n => [
                    'domain' => 'fieldnote-'.$n.'.example', 'status' => $n === 1 ? 'needs_review' : 'active',
                    'note' => $n === 1 ? 'Compare the renewal price with the registrar cost.' : null,
                    'customer' => $n === 1 ? 'Maximiliana Featherstonehaugh' : 'Ada Park', 'amount' => '14.99', 'currency' => $n % 2 ? 'USD' : 'EUR',
                ]])->filter(static fn (array $r): bool => (blank($search) || str_contains($r['domain'], $search))
                    && (blank($filters['status']['value'] ?? null) || $r['status'] === $filters['status']['value']));
                return new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
            })->recordUrl(static fn (): string => PanelKitDetail::getUrl())->paginated([25, 50])->defaultPaginationPageOption(25)
            ->filters([\Filament\Tables\Filters\SelectFilter::make('status')->options(['active' => State::Active->label(), 'needs_review' => State::NeedsReview->label()])])
            ->recordActions([Sheet::link('kit_view', 'View', PanelKitDetail::getUrl())]);
    }
    /** Nov 12, 2026, 17:12 UTC, the kit's "now". */
    public const AT = 1794503520;
    public const EXPIRES = 1804180320;
    private const WORDS = [
        'OK' => [State::Ok, State::Active, State::Monitored, State::Completed, State::Paid, State::ForSale],
        'Off' => [State::NotReadYet, State::NotIssuedYet, State::NotForSale, State::NotSupported, State::NotConnected, State::Paused, State::Stopped, State::Cancelled, State::NotRecognized, State::Unpaid],
        'In progress' => [State::Checking, State::Queued, State::Running, State::PendingRegistration, State::PendingTransfer, State::AwaitingPayment],
        'Needs attention' => [State::NotSet, State::NeedsReview, State::ActionRequired, State::OrdersPaused, State::ExpiresToday, State::LowBalance, State::BelowCost, State::Delayed, State::OutOfDate, State::UnderReview],
        'Failed' => [State::CouldntRead, State::Failed, State::ConnectionFailed, State::Expired, State::Redemption],
    ];
    /** Every word of one tone, as staff read it. @param list<State> $states */
    private static function words(array $states): Component
    { return Html::make(new HtmlString('<div class="domains-panel-kit-words">'.implode('', array_map(static fn (State $s): string => Value::state($s, Reader::Staff)->toHtml(), $states)).'</div>')); }
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
    public static function kit(string $name, string $label, ?string $row = null): Action
    {
        $action = Sheet::button(Action::make('kit_'.$name)->label($label)->action(static fn (): null => null));
        return $row === null ? $action : $action->extraAttributes(['aria-label' => $label.' '.$row]);
    }
}
