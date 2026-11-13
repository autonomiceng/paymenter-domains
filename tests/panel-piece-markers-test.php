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
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, SettingsRow, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{EmptyTable, Sheet, Staff};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader, State};

panelKitFixture();
$writer = staffUser([Staff::VIEW, Staff::POLICY]);
$page = Livewire::actingAs($writer)->test(PanelKit::class)->assertSuccessful();
// A notice and a switch row placed on the same page, as a render does.
$act = static fn (string $name): Action => Action::make($name)->action(static fn (): null => null);
$notice = Row::notice('kit-paused', Value::state(State::OrdersPaused, Reader::Staff))->actions($act('resume'));
$toggle = SettingsRow::toggle('Watch', true, $act('watch'), 'Set by staff.');
Schema::make($page->instance())->components([$notice, $toggle])->getComponents();
$dom = storefrontDom($page->html().$notice->toEmbeddedHtml().$toggle->toEmbeddedHtml());

// Each piece's root element carries its name, and no other element claims it.
$roots = [
    'head-title' => 'span[@class="domains-panel-head-title"]', 'head-line' => 'span[@class="domains-panel-head-line"]',
    'row' => 'div[@class="domains-panel-row"][@data-domains-row!="setting"][@data-domains-row!="notice"]',
    'settings-row' => 'div[@class="domains-panel-row"][@data-domains-row="setting"]', 'notice' => 'div[@class="domains-panel-row"][@data-domains-row="notice"]',
    'value' => 'div[@class="domains-panel-val"]', 'fact' => '*[@class="domains-panel-fact"]', 'switch' => 'button[@role="switch"]',
    'trace' => 'ol[@class="domains-panel-trace"]', 'section' => '*[contains(concat(" ",@class," ")," domains-sec ")]',
    'split' => '*[contains(concat(" ",@class," ")," domains-split ")]', 'sheet' => '*[contains(concat(" ",@class," ")," domains-sheet ")]',
    'rail' => '*[contains(concat(" ",@class," ")," domains-rail ")]',
];
foreach ($roots as $piece => $root) {
    $all = $dom->evaluate('count(//'.$root.')');
    expect($all > 0, 'The kit draws a '.$piece);
    same([$all, $all], [$dom->evaluate('count(//'.$root.'[@data-piece="'.$piece.'"])'), $dom->evaluate('count(//*[@data-piece="'.$piece.'"])')], 'Every '.$piece.' and only a '.$piece.' carries its marker');
}

// Pieces drawn by Filament from their attributes: the older sheet row and trace, and a staff table, whose empty state draws inside it.
same(['sheet-row', 'sheet-trace', 'table'], [
    Sheet::row(TextEntry::make('lock'))->getExtraAttributes()['data-piece'] ?? null,
    Sheet::trace([['label' => 'Read', 'tone' => 'success']])->getExtraAttributes()['data-piece'] ?? null,
    EmptyTable::apply(Table::make(new ListDomains()), 'No domains yet', 'They show here.', 'No domains match')->getExtraAttributes()['data-piece'] ?? null,
], 'The older sheet pieces and staff tables carry their markers');
$nativeSuccessful = true;
echo "Panel piece markers hold.\n";
