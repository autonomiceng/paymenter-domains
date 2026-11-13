<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The four page types on the kit: each example opens for staff only, a
// list and a history search and filter their rows, and a page that hands a type anything but data is refused.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PanelKit, PanelKitDetail, PanelKitHistory, PanelKitList, PanelKitRecords, PanelKitSettings};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, SettingsRow, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{DetailPage, Section, SettingsPage};
use Paymenter\Extensions\Servers\Domains\Presentation\{Plain, Reader, State};

panelKitFixture();
$staff = staffUser([Staff::VIEW]);
$examples = [PanelKitDetail::class, PanelKitSettings::class, PanelKitList::class, PanelKitRecords::class, PanelKitHistory::class];
foreach ($examples as $page) {
    Livewire::actingAs($staff)->test($page)->assertSuccessful();
    Auth::setUser(staffUser([]));
    same(false, $page::canAccess(), class_basename($page).' is closed to staff without domain access');
}
// Each example is one tab on the kit, so the kit reaches every type.
Auth::setUser($staff);
same(['Pieces', 'Detail', 'Settings', 'List', 'Record list', 'History'], array_map(static fn ($item): string => $item->getLabel(), PanelKit::tabs('Detail')), 'The kit has one tab per type');
// The head's actions are the page's own native actions.
Livewire::actingAs($staff)->test(PanelKitDetail::class)->assertActionExists('kit_renew')->callAction('kit_renew')->assertHasNoActionErrors();
// M0's toggle rows that are not settings stay on the kit through the Detail type, with their own buttons.
Livewire::actingAs($staff)->test(PanelKitDetail::class)->assertSeeHtml('aria-label="Turn off Transfer lock"')->assertSeeText('Check balance');
// A step carries its one action or link, and a link that promises a row lands on it: the row's key is its id, marked only when asked for.
$detail = Livewire::actingAs($staff)->test(PanelKitDetail::class);
$dom = new DOMXPath((static function (string $h): DOMDocument { $d = new DOMDocument; @$d->loadHTML($h); return $d; })($detail->html()));
$step = static fn (string $label): ?DOMElement => $dom->query('//li[@class="domains-panel-step"][.//*[@class="domains-panel-step-t" and normalize-space(.)="'.$label.'"]]')->item(0);
same([1, 1, '#switch-lock', 0], [$dom->query('.//button[@*[name()="wire:click"]]', $step('Registrar read'))->length, $dom->query('.//a', $step('Auto-renew at registrar'))->length,
    $dom->query('.//a', $step('Auto-renew at registrar'))->item(0)?->getAttribute('href'), $dom->query('.//button|.//a', $step('Invoice 100'))->length], 'A step shows only its own action or link');
preg_match('/mountAction\(\'kit_read\'.*?schemaComponent\\\\u0022:\\\\u0022([^\\\\]+)\\\\u0022/', $detail->html(), $m);
$ran = [];Illuminate\Support\Facades\Event::listen(Filament\Actions\Events\ActionCalling::class, static function (Filament\Actions\Action $a) use (&$ran): void { $ran[] = $a->getName(); });
$detail->call('mountAction', 'kit_read', [], ['schemaComponent' => $m[1] ?? 'none'])->callMountedAction()->assertHasNoActionErrors();
same(['kit_read'], $ran, 'The step action runs');
same([1, 0], [$dom->query('//div[@data-domains-row][@id="switch-lock"]')->length, $dom->query('//*[contains(@class,"domains-row-target")]')->length], 'The target row has its key as id and no mark unasked');
same(1, substr_count(Livewire::actingAs($staff)->withQueryParams(['switch' => 'lock'])->test(PanelKitDetail::class)->html(), 'domains-row-target'), 'The row a link came for is marked');
expect((static function (): bool { try { Row::make('X', Value::plain(Plain::On))->key('Bad Key')->target(); } catch (InvalidArgumentException) { return true; } return false; })(), 'A target row needs a plain key');

// A list searches and filters its rows, and each row opens its record.
$list = static fn () => Livewire::actingAs($staff)->test(PanelKitList::class);
$shown = static fn ($test): array => array_values(array_filter(['fieldnote.example', 'harbor-lights.example', 'quietfield.example', 'saltmarsh.example'], static fn (string $d): bool => str_contains(html_entity_decode(strip_tags($test->html())), $d)));
same(4, count($shown($list())), 'Every row is listed');
same(['harbor-lights.example'], $shown($list()->searchTable('harbor')), 'Search keeps the matching row');
same(['quietfield.example'], $shown($list()->filterTable('status', 'paused')), 'The status filter keeps the paused row');
same([], $shown($list()->searchTable('nothing-like-this')), 'A search with no match lists nothing');
expect(str_contains($list()->html(), PanelKitDetail::getUrl()), 'A row opens its record');
// A bulk action gets the ticked rows by their key, also after a search.
$list()->searchTable('harbor')->callTableBulkAction('kit_pause', ['harbor-lights.example'])->assertNotified('Paused harbor-lights.example');

// A saved-record list searches, sorts, filters and pages in the database, and its bulk action gets the ticked records.
fixtureAccount(2, 'namesilo', 'kit-records-2');fixtureAccount(3, 'enom', 'kit-records-3');
\App\Models\Server::whereKey(2)->update(['name' => 'Harbor NameSilo']);\App\Models\Server::whereKey(3)->update(['name' => 'Quiet Enom']);
\Paymenter\Extensions\Servers\Domains\Models\Account::query()->where('server_id', 3)->update(['environment' => \Paymenter\Extensions\Servers\Domains\Data\Environment::Live->value]);
$accounts = static fn ($test): array => array_map(static fn ($a): int => (int) $a->server_id, array_values($test->instance()->getTableRecords()->all()));
$records = static fn () => Livewire::actingAs($staff)->test(PanelKitRecords::class);
same([1, 2, 3], $accounts($records()), 'Every account is listed in its default order');
same([2], $accounts($records()->searchTable('Harbor')), 'Search reads the column\'s own query');
same([3, 2, 1], $accounts($records()->sortTable('server_id', 'desc')), 'Sort runs in the database');
same([2], $accounts($records()->filterTable('registrar_key', 'namesilo')), 'A select filter runs its query');
same([3], $accounts($records()->filterTable('live')), 'A switch filter runs its query');
$ids = \Paymenter\Extensions\Servers\Domains\Models\Account::query()->whereIn('server_id', [1, 3])->pluck('id')->all();
$records()->callTableBulkAction('kit_check', $ids)->assertNotified('Checked 2 accounts');

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
// A record Detail page also polls at its own 2 or 10 second cadence, kept alive while it follows a change, and places a
// shared partial view in a section. Other types keep 5 or 60 seconds and no partials.
$fast = new class extends DetailPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function sections(): array { return [Section::make('Records', [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial::make('domains::partials.moment', ['at' => '2026-03-04T05:06:07Z'])])]; }
    public function refresh(): void {}
    protected function poll(): ?array { return ['refresh', 2, true]; }
};
$refused(static fn () => $fast->getHeader(), 'A Detail page outside a record keeps 5 or 60 seconds');
$refused(static fn () => $fast->content(Filament\Schemas\Schema::make($fast)), 'A partial view belongs on a record Detail page');
$refused(static fn () => Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial::make('filament-panels::pages.page'), 'A partial is one of the extension\'s own views');
$record = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordDetailPage {
    protected static string $resource = Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class;
    public int $every = 2;
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function sections(): array { return [Section::make('Records', [Row::make('Mode', Value::plain(Plain::Test)), Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial::make('domains::partials.moment', ['at' => '2026-03-04T05:06:07Z'])])]; }
    public function refresh(): void {}
    protected function poll(): ?array { return ['refresh', $this->every, $this->every === 2]; }
};
same(['refresh', 2, true], $record->getHeader()->getData()['poll'], 'A record page follows a change every 2 seconds, also while hidden');
$record->every = 10;same(['refresh', 10, false], $record->getHeader()->getData()['poll'], 'A record page reads a slow change every 10 seconds');
$record->every = 3;$refused(static fn () => $record->getHeader(), 'A record page polls only at a cadence the type knows');
$partial = collect($record->content(Filament\Schemas\Schema::make($record))->getFlatComponents(false))->first(static fn ($c) => $c instanceof Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial);
expect($partial !== null && str_contains($partial->toHtml(), '<time'), 'A record page places the shared partial in its section');
$refused(static fn () => Section::make('Domain', [Row::make('Status', Value::plain(Plain::On)), Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial::make('domains::partials.moment', ['at' => null]), Row::notice('late', Value::state(State::Failed, Reader::Staff, 'It broke.'))]), 'A notice after a partial is still refused');
$rows = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\ListPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function columns(): array { return [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::text('name', 'Name')]; }
    protected function filters(): array { return [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Filter::toggle('live', 'Live', static fn ($q) => $q)]; }
    protected function rows(): array { return []; }
    protected function emptyLine(): array { return ['None yet.', 'Add one.']; }
    protected function bulkActions(): array { return [Filament\Actions\Action::make('pause')]; }
};
$refused(static fn () => $rows->table(Filament\Tables\Table::make($rows)), 'An array list refuses a filter that runs a query');
// A multiple pick keeps the rows that match any picked value, and a single pick keeps its own.
same([true, false, true, true], [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Filter::keeps(['values' => ['paused', 'failed']], 'failed'), Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Filter::keeps(['values' => ['paused', 'failed']], 'monitored'), Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Filter::keeps(['value' => 'paused'], 'paused'), Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Filter::keeps(['value' => null], 'monitored')], 'A list filter keeps one value or any of several');
// An optional column starts hidden and the Columns menu can show it, also on a list of four columns or fewer.
$note = Paymenter\Extensions\Servers\Domains\Admin\Support\TableColumns::choose(Filament\Tables\Table::make($rows), [Filament\Tables\Columns\TextColumn::make('name'), Filament\Tables\Columns\TextColumn::make('note')], ['note'])->getColumns()['note'];
expect($note->isToggleable() && $note->isToggledHiddenByDefault(), 'An optional column starts hidden and can be shown, on a short list too');

// P-TBL-12 on phones: a value marked to stack sits under the name and its column waits for sm, so each reads once.
$Column = Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::class;
$stacked = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\ListPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function columns(): array
    {
        $c = Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::class;
        return [$c::domain('domain', 'Domain'), $c::value('status', 'Status', static fn (array $r): Value => Value::state(State::Failed, Reader::Staff))->stackOnPhone(),
            $c::text('note', 'Note')->stackOnPhone(), $c::text('customer', 'Customer')];
    }
    protected function rows(): array { return []; }
    protected function emptyLine(): array { return ['None yet.', 'Add one.']; }
};
$table = $stacked->table(Filament\Tables\Table::make($stacked));
$under = trim(html_entity_decode(strip_tags(str_replace('<br>', "\n", (string) $table->getColumn('domain')->record(['domain' => 'a.example', 'note' => 'Check <the> request.'])->getDescriptionBelow()))));
same("Failed\nCheck <the> request.", $under, 'A phone reads the status word and the note under the name, escaped');
$refused(static fn () => $Column::text('x', 'X')->stackOnPhone()->from('md'), 'A stacked column takes no other width');
$refused(static fn () => $Column::text('x', 'X')->from('md')->stackOnPhone(), 'A column with a width does not stack');
$first = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\ListPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function columns(): array { return [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::text('name', 'Name')->stackOnPhone()]; }
    protected function rows(): array { return []; }
    protected function emptyLine(): array { return ['None yet.', 'Add one.']; }
};
$refused(static fn () => $first->table(Filament\Tables\Table::make($first)), 'The name column never stacks under itself');

// Permission-hidden fields are never evaluated for a phone description.
$grant = false;
$private = $Column::text('private', 'Private', static fn (array $r): string => throw new LogicException('A hidden field must not be read.'))->stackOnPhone()->visibleWhen(static fn (): bool => $grant);
same(null, $private->phoneText([]), 'A permission-hidden column contributes no phone text');
// Zero is a real count, including on a phone.
$zero = $table->getColumn('domain')->record(['domain' => 'a.example', 'note' => '0'])->getDescriptionBelow();
expect(preg_match('/\b0\b/', strip_tags(str_replace('<br>', ' ', (string) $zero))) === 1, 'A phone keeps a zero value');
// List summaries have the same partial-view boundary as other non-record sections.
$summary = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\ListPage {
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function columns(): array { return [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::text('name', 'Name')]; }
    protected function rows(): array { return []; }
    protected function emptyLine(): array { return ['None yet.', 'Add one.']; }
    protected function summary(): array { return [Section::make('Account', [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Partial::make('domains::partials.moment', ['at' => null])])]; }
};
$refused(static fn () => $summary->content(Filament\Schemas\Schema::make($summary)), 'A list summary refuses partial views');

// A history says what needs saying above its table, in a notice as on a list; anything else there is refused.
$noticed = new class extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\HistoryPage {
    public bool $plain = false;
    protected function head(): Head { return Head::make('Example', Reader::Staff); }
    protected function events(): ?array { return []; }
    protected function notices(): array
    {
        return $this->plain ? [Section::make('Account', [Row::make('Mode', Value::plain(Plain::Test))])]
            : [Section::make('Account', [Row::notice('off', Value::state(State::NotConnected, Reader::Staff, 'Off in Paymenter.'))])];
    }
};
same(2, count($noticed->content(Filament\Schemas\Schema::make($noticed))->getComponents()), 'A history with a notice shows it above the table');
$noticed->plain = true;
$refused(static fn () => $noticed->content(Filament\Schemas\Schema::make($noticed)), 'A history refuses a section that does not open with a notice');

// A row carries native actions only, and at most two before they go in a menu.
foreach ([[static fn () => ['<a>Open</a>'], 'A row action is a native action'], [static fn () => [Filament\Actions\Action::make('a'), Filament\Actions\Action::make('b'), Filament\Actions\Action::make('c')], 'A row has at most two actions']] as [$made, $why]) {
    $rowActions = new class($made) extends Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\ListPage {
        public function __construct(private \Closure $made) {}
        protected function head(): Head { return Head::make('Example', Reader::Staff); }
        protected function columns(): array { return [Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Column::text('name', 'Name')]; }
        protected function rows(): array { return []; }
        protected function emptyLine(): array { return ['None yet.', 'Add one.']; }
        protected function recordActions(): array { return ($this->made)(); }
    };
    $refused(static fn () => $rowActions->table(Filament\Tables\Table::make($rowActions)), $why);
}

$nativeSuccessful = true;
echo "Four page types on the kit: staff only, list and history search and filter, data only.\n";
