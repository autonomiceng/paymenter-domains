<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet, Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, SettingsRow, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Section, SettingsPage};
use Paymenter\Extensions\Servers\Domains\Presentation\{Plain, Reader};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The kit's Settings example: the M0 toggle sheet's settings (on, off, blocked, someone else's) supplied as data only.
 * Its Balance and Transfer lock rows are not settings, so they sit on the Detail example.
 * Demo systems only, like the kit. Its data is made up and its switches run nothing. */
class PanelKitSettings extends SettingsPage
{
    protected static ?string $slug = 'panel-kit/settings';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return PanelKit::canAccess(); }
    public function mount(): void { Staff::requireView(); abort_unless(DemoData::present(), 404); }
    public function getSubNavigation(): array { return PanelKit::tabs('Settings'); }
    protected function head(): Head { return Head::make('Enom TEST settings', Reader::Staff)->tag(true, false); }
    protected function sections(): array
    {
        $account = 'Set by staff on the registrar account.';
        return [
            Section::make('Orders', [
                SettingsRow::toggle('Registrations', true, PanelKit::kit('registrations', 'Registrations'), $account),
                SettingsRow::toggle('Renewals', false, PanelKit::kit('renewals', 'Renewals'), $account),
                SettingsRow::toggle('Transfers', false, PanelKit::kit('transfers', 'Transfers'), $account)
                    ->blocked('Transfers can\'t be turned on without a Live registrar account.', Sheet::link('kit_live', 'Connect a Live account', '#sec-orders')),
            ]),
            Section::make('Registrar account', [
                SettingsRow::toggle('Low balance alert', false, PanelKit::kit('low_balance', 'Low balance alert'), $account, 'Emails staff when the balance falls below $25.00.'),
            ], 'All amounts in USD.'),
            Section::make('Payment', [SettingsRow::setting('Auto Pay', Value::plain(Plain::On), 'Set by the customer on their payment page.')]),
        ];
    }
}
