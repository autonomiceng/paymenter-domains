<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\HistoryPage;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** The kit's History example: the Detail example's record and its events, newest first, supplied as data only.
 * Demo systems only, like the kit. */
class PanelKitHistory extends HistoryPage
{
    protected static ?string $slug = 'panel-kit/history';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return PanelKit::canAccess(); }
    public function mount(): void { Staff::requireView(); abort_unless(DemoData::present(), 404); }
    public function getSubNavigation(): array { return PanelKit::tabs('History'); }
    protected function head(): Head
    { return Head::make('fieldnote.example', Reader::Staff)->domain()->tag(true, false)->state(State::Active, 'kit-detail')->fact('Expires', Fact::day('2027-03-04')); }
    protected function events(): ?array
    {
        $at = static fn (int $offset): string => gmdate('c', PanelKit::AT - $offset);
        return [
            ['time' => $at(0), 'change' => 'Renewal sent to Enom TEST', 'reason' => 'Renewing for 1 year.', 'by' => 'System'],
            ['time' => $at(60), 'change' => 'Invoice 100 paid', 'reason' => '', 'by' => 'the customer'],
            ['time' => $at(4320), 'change' => 'Registrar read failed', 'reason' => 'We couldn\'t reach the registrar.', 'by' => 'System'],
            ['time' => $at(950400), 'change' => 'Auto-renew at registrar stopped', 'reason' => 'The domain renews after the invoice is paid.', 'by' => 'Ilya'],
        ];
    }
}
