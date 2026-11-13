<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{DetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The kit's Detail example: the M0 frame (head, sheet, the Activity rail) supplied as data only. It also holds the M0
 * toggle rows that are not settings: Transfer lock waits on the registrar, so it is a button on the domain's sheet, and
 * Balance with Check balance is a fact about the account on the rail. The Settings example keeps the switches.
 * Demo systems only, like the kit. Its data is made up and its buttons run nothing. */
class PanelKitDetail extends DetailPage
{
    protected static ?string $slug = 'panel-kit/detail';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return PanelKit::canAccess(); }
    public function mount(): void { Staff::requireView(); abort_unless(DemoData::present(), 404); }
    public function getSubNavigation(): array { return PanelKit::tabs('Detail'); }
    protected function head(): Head
    {
        return Head::make('fieldnote.dev', Reader::Staff)->domain()->tag(true, false)->state(State::Active, 'kit-detail')
            ->fact('Expires', Fact::day('2027-03-04'))->link('Enom TEST', '#sec-registration');
    }
    protected function actions(): array { return [PanelKit::kit('renew', 'Renew')]; }
    protected function sections(): array
    {
        $staff = Reader::Staff;
        return [Section::make('Registration', [
            Row::make('Renewal', Value::state(State::Checking, $staff, 'Renewing for 1 year at Enom TEST.')->when(PanelKit::AT)),
            Row::make('Registrar', Value::fact(Fact::names(['Enom TEST'])))->actions(Sheet::link('kit_account', 'Open account', '#sec-registration')),
            Row::make('Expires', Value::fact(Fact::date(PanelKit::EXPIRES, $staff))),
            Row::make('Nameservers', Value::fact(Fact::names(['ns1.example.net', 'ns2.example.net'])))->actions(PanelKit::kit('ns', 'Edit', 'Nameservers')),
        ]), Section::make('Domain lock', [
            Row::make('Transfer lock', Value::plain(Plain::On))->actions(PanelKit::kit('lock', 'Turn off', 'Transfer lock')),
        ])];
    }
    protected function rail(): array
    {
        return [Section::make('Activity', [Trace::make(Reader::Staff)
            ->step('Renewal sent to Enom TEST', State::Checking, PanelKit::AT, null, null, 'kit-detail-renewal')
            ->step('Invoice 100', State::Paid, PanelKit::AT - 60, 'the customer')
            ->step('Registrar read', State::CouldntRead, PanelKit::AT - 4320, null, 'We couldn\'t reach the registrar.')
            ->step('Auto-renew at registrar', State::Stopped, PanelKit::AT - 950400, 'Ilya')])
            ->link(Sheet::link('kit_history', 'History', PanelKitHistory::getUrl())),
            Section::make('Registrar account', [
                Row::make('Balance', Value::fact(Fact::money('1240.00')))->actions(PanelKit::kit('balance', 'Check balance')),
            ], 'All amounts in USD.')];
    }
}
