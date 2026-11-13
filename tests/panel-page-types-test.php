<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The four page types on the kit: each example opens for staff only, a
// list and a history search and filter their rows, and a page that hands a type anything but data is refused.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PanelKit, PanelKitDetail, PanelKitHistory, PanelKitList, PanelKitSettings};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, SettingsRow, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{DetailPage, Section, SettingsPage};
use Paymenter\Extensions\Servers\Domains\Presentation\{Plain, Reader, State};

panelKitFixture();
$staff = staffUser([Staff::VIEW]);
$examples = [PanelKitDetail::class, PanelKitSettings::class, PanelKitList::class, PanelKitHistory::class];
foreach ($examples as $page) {
    Livewire::actingAs($staff)->test($page)->assertSuccessful();
    Auth::setUser(staffUser([]));
    same(false, $page::canAccess(), class_basename($page).' is closed to staff without domain access');
}
// Each example is one tab on the kit, so the kit reaches every type.
Auth::setUser($staff);
same(['Pieces', 'Detail', 'Settings', 'List', 'History'], array_map(static fn ($item): string => $item->getLabel(), PanelKit::tabs('Detail')), 'The kit has one tab per type');
// The head's actions are the page's own native actions.
Livewire::actingAs($staff)->test(PanelKitDetail::class)->assertActionExists('kit_renew')->callAction('kit_renew')->assertHasNoActionErrors();
// M0's toggle rows that are not settings stay on the kit through the Detail type, with their own buttons.
Livewire::actingAs($staff)->test(PanelKitDetail::class)->assertActionExists('kit_lock')->assertActionExists('kit_balance');

// A list searches and filters its rows, and each row opens its record.
$list = static fn () => Livewire::actingAs($staff)->test(PanelKitList::class);
$shown = static fn ($test): array => array_values(array_filter(['fieldnote.dev', 'harbor-lights.example', 'quietfield.example', 'saltmarsh.example'], static fn (string $d): bool => str_contains(html_entity_decode(strip_tags($test->html())), $d)));
same(4, count($shown($list())), 'Every row is listed');
same(['harbor-lights.example'], $shown($list()->searchTable('harbor')), 'Search keeps the matching row');
same(['quietfield.example'], $shown($list()->filterTable('status', 'paused')), 'The status filter keeps the paused row');
same([], $shown($list()->searchTable('nothing-like-this')), 'A search with no match lists nothing');
expect(str_contains($list()->html(), PanelKitDetail::getUrl()), 'A row opens its record');

// A history searches its events and filters by who made them.
$events = static fn ($test): int => count(array_filter(['Renewal sent to Enom TEST', 'Invoice 100 paid', 'Registrar read failed', 'Auto-renew at registrar stopped'], static fn (string $e): bool => str_contains(html_entity_decode(strip_tags($test->html())), $e)));
$history = static fn () => Livewire::actingAs($staff)->test(PanelKitHistory::class);
same(4, $events($history()), 'Every event is listed');
same(1, $events($history()->searchTable('Invoice 100')), 'Search keeps the matching event');
same(1, $events($history()->filterTable('by', 'Ilya')), 'The By filter keeps that person\'s events');

// A page supplies data only: anything else is refused before it renders.
$refused = static function (callable $build, string $what): void {
    try { $build(); } catch (InvalidArgumentException) { expect(true, $what); return; }
    expect(false, $what);
};
$refused(static fn () => Section::make('Domain', [new Filament\Schemas\Components\Html('<b>x</b>')]), 'A section refuses markup');
$refused(static fn () => Section::make('Domain', [Row::make('Status', Value::plain(Plain::On)), Row::notice('late', Value::state(State::Failed, Reader::Staff, 'It broke.'))]), 'A notice that is not first is refused');
$settings = new class extends SettingsPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function sections(): array { return [Section::make('Orders', [Row::make('Balance', Value::plain(Plain::None))])]; }
};
$refused(static fn () => $settings->content(Filament\Schemas\Schema::make($settings)), 'A settings page refuses a row that is not a setting');
$detail = new class extends DetailPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function sections(): array { return [Section::make('Domain', [SettingsRow::setting('Mode', Value::plain(Plain::Test), 'Set by staff.')])]; }
    protected function rail(): array { return ['<aside>Activity</aside>']; }
    protected function actions(): array { return ['<button>Renew</button>']; }
    protected function poll(): ?array { return ['notAMethod', 5]; }
};
$refused(static fn () => $detail->content(Filament\Schemas\Schema::make($detail)), 'A rail refuses anything but sections');
$refused(static fn () => (new ReflectionMethod($detail, 'getHeaderActions'))->invoke($detail), 'A header refuses anything but native actions');
$refused(static fn () => $detail->getHeader(), 'A page polls only one of its own methods');

$nativeSuccessful = true;
echo "Four page types on the kit: staff only, list and history search and filter, data only.\n";
