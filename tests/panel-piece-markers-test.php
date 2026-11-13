<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Every panel piece names itself with data-piece on its root element, so a walk can crop each one.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/storefront.php';
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PanelKit;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, SettingsRow, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable, Sheet, Staff};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};

panelKitFixture();
$writer = staffUser([Staff::VIEW, Staff::POLICY]);
$page = Livewire::actingAs($writer)->test(PanelKit::class)->assertSuccessful();
// A notice and a switch row placed on the same page, as a render does.
$act = static fn (string $name): Action => Action::make($name)->action(static fn (): null => null);
$notice = Row::notice('kit-paused', Value::state(State::OrdersPaused, Reader::Staff))->actions($act('resume'));
$toggle = SettingsRow::toggle('Watch', true, $act('watch'), 'Set by staff.');
$plain = Row::make('Transfer lock', Value::plain(Plain::On));
$steps = Trace::make(Reader::Staff)->step('Read', State::Completed);
Schema::make($page->instance())->components([$notice, $toggle, $plain, $steps])->getComponents();
$dom = storefrontDom($page->html().$notice->toEmbeddedHtml().$toggle->toEmbeddedHtml());

// The kit draws every piece, each found by its marker alone.
foreach (['head-title', 'head-line', 'row', 'settings-row', 'notice', 'value', 'fact', 'switch', 'trace', 'section', 'split', 'sheet', 'rail'] as $piece) {
    expect($dom->evaluate('count(//*[@data-piece="'.$piece.'"])') > 0, 'The kit draws a '.$piece);
}
// Drawn alone, each piece's outermost element carries its own marker, whatever classes it has.
$root = static fn (string $html): ?string => storefrontDom($html)->query('//body/*[1]')->item(0)?->getAttribute('data-piece');
$head = Head::make('Example', Reader::Staff)->state(State::Paused, 'kit-head');
same([
    'head-title' => 'head-title', 'head-line' => 'head-line', 'row' => 'row', 'settings-row' => 'settings-row', 'notice' => 'notice',
    'value' => 'value', 'fact' => 'fact', 'trace' => 'trace',
], [
    'head-title' => $root($head->heading()->toHtml()), 'head-line' => $root((string) $head->line()?->toHtml()),
    'row' => $root($plain->toEmbeddedHtml()), 'settings-row' => $root($toggle->toEmbeddedHtml()),
    'notice' => $root($notice->toEmbeddedHtml()), 'value' => $root(Value::state(State::Paused, Reader::Staff)->toHtml()),
    'fact' => $root(view('domains::components.panel.fact', ['fact' => Fact::money('12.00')])->render()),
    'trace' => $root($steps->toEmbeddedHtml()),
], 'Each piece names itself on its outermost element');
// The switch marker sits on the control itself, the element a reader toggles.
same('switch', storefrontDom($toggle->toEmbeddedHtml())->query('//*[@role="switch"]')->item(0)?->getAttribute('data-piece'), 'The switch control carries its marker');
// Layout pieces are Filament components: their own root attributes carry the marker.
$split = Sheet::split([Sheet::section('Lock', [])], [Sheet::section('Account', [])], 'kit');
[$sheet, $rail] = $split->getDefaultChildComponents();
same(['split', 'sheet', 'rail', 'section'], [
    $split->getExtraAttributes()['data-piece'] ?? null, $sheet->getExtraAttributes()['data-piece'] ?? null,
    $rail->getExtraAttributes()['data-piece'] ?? null, Sheet::section('Lock', [])->getExtraAttributes()['data-piece'] ?? null,
], 'Split, sheet, rail and section carry their markers on their roots');

// Pieces drawn by Filament from their attributes: the older sheet row and trace, and a staff table, whose empty state draws inside it.
same(['sheet-row', 'sheet-trace', 'table'], [
    Sheet::row(TextEntry::make('lock'))->getExtraAttributes()['data-piece'] ?? null,
    Sheet::trace([['label' => 'Read', 'tone' => 'success']])->getExtraAttributes()['data-piece'] ?? null,
    EmptyTable::apply(Table::make(new ListDomains()), 'No domains yet', 'They show here.', 'No domains match')->getExtraAttributes()['data-piece'] ?? null,
], 'The older sheet pieces and staff tables carry their markers');
$nativeSuccessful = true;
echo "Panel piece markers hold.\n";
