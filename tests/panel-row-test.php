<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Panel rows, settings rows and traces: what each reader can use, why a control is blocked, the waiting edge and step tones.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/storefront.php';
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PanelKit;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, SettingsRow, Trace, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};

$server = panelKitFixture();
$at = 1794503520; // Nov 12, 2026, 17:12 UTC
$fails = static function (Closure $make, string $class = InvalidArgumentException::class): bool { try { $make(); } catch (Throwable $e) { return $e instanceof $class; } return false; };
$act = static fn (string $name): Action => Action::make($name)->action(static fn (): null => null);
$on = Value::plain(Plain::On);

// What a page can build: a label, at most two actions, a notice with a state and one action, states for live and result lines.
expect($fails(static fn () => Row::make(' ', $on)), 'A row needs its label');
expect($fails(static fn () => Row::make('Lock', $on)->actions($act('a'), $act('b'), $act('c'))), 'A row has at most two actions');
expect(!$fails(static fn () => Row::make('Lock', $on)->actions($act('a'), $act('b'))), 'Two actions fit');
expect($fails(static fn () => Row::notice('paused', $on)), 'A notice starts with a state');
expect($fails(static fn () => Row::notice('paused', Value::state(State::OrdersPaused, Reader::Staff))->actions($act('a'), $act('b'))), 'A notice has one action');
expect($fails(static fn () => Row::make('Lock', $on)->running($on)) && $fails(static fn () => Row::make('Lock', $on)->result(Value::fact(Fact::count(2)))), 'Live and result lines are states');
expect($fails(static fn () => Row::make('Lock', $on)->blockedBy(Row::make('Other', $on))), 'Only a notice blocks a section');
expect($fails(static fn () => SettingsRow::setting('Mode', $on, 'Set by staff')), 'Who sets a setting is a sentence');
expect($fails(static fn () => SettingsRow::toggle('DNS', true, $act('a'), 'Set by staff.')->actions($act('a'), $act('b'))), 'A switch row has one control');
expect($fails(static fn () => SettingsRow::make('Mode', $on)->toEmbeddedHtml(), LogicException::class), 'A setting always says who sets it');
expect($fails(static fn () => Trace::make(Reader::Staff)->step('', State::Paid)) && $fails(static fn () => Trace::make(Reader::Staff)->step('Invoice', State::Paid, $at, null, 'Paid')), 'A step has a label and a reason that is no state word');
same([true, false, true], [Row::make('A', Value::state(State::Checking, Reader::Staff))->waits(), Row::make('A', Value::state(State::Running, Reader::Staff))->waits(),
    Row::make('A', $on)->running(Value::state(State::Checking, Reader::Staff))->waits()], 'Only Checking waits, in the value or the live line');

// A trace: tones by reader, staff read the time and customers the date.
$trace = static fn (Reader $reader): DOMXPath => storefrontDom(Trace::make($reader)->step('Transfer request', State::UnderReview, $at, 'the customer')->step('Invoice #100', State::Unpaid, $at - 60)->toEmbeddedHtml());
foreach ([[Reader::Staff, ['warn', 'off'], '2026-11-12T17:12:00+00:00', 2.0], [Reader::Customer, ['prog', 'warn'], '2026-11-12', 0.0]] as [$reader, $tones, $datetime, $converted]) {
    $dom = $trace($reader);
    same($tones, array_map(static fn (DOMNode $n): string => $n->nodeValue, iterator_to_array($dom->query('//li[@class="domains-panel-step"]/@data-domains-tone'))), 'Step tones for '.$reader->name);
    same(['Under review', 'Unpaid'], array_map(static fn (DOMNode $n): string => trim($n->nodeValue), iterator_to_array($dom->query('//*[@class="domains-panel-step-w"]'))), 'Step words are the same for both readers');
    same([$datetime, $converted], [$dom->evaluate('string((//time)[1]/@datetime)'), $dom->evaluate('count(//time[@data-domains-time])')], 'Step time for '.$reader->name.'; staff times convert in the browser');
    same([true, false], [str_contains($dom->evaluate('string((//li)[1])'), 'the customer'), str_contains($dom->evaluate('string((//li)[2])'), 'the customer')], 'Only the step given a who names it');
}
// A history event with no state of its own (a note, a request) draws no mark and no word; a monitoring change keeps its state.
$rail = storefrontDom(\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchRows::trace([['event' => 'Note', 'note' => null, 'time' => null, 'by' => 'Staff #4'],
    ['event' => 'Check requested', 'note' => null, 'time' => null, 'by' => 'Staff #4'], ['event' => 'Monitoring paused', 'note' => null, 'time' => null, 'by' => 'Staff #4']])->toEmbeddedHtml());
same(['', '', \Paymenter\Extensions\Servers\Domains\Presentation\State::Paused->label()], array_map(static fn (DOMNode $li): string => trim($rail->evaluate('string(*[@class="domains-panel-step-w"])', $li)), iterator_to_array($rail->query('//li'))), 'Neutral events do not invent a lifecycle state');

// The kit, as staff who can change policy read it.
$writer = staffUser([Staff::VIEW, Staff::POLICY]);
$html = Livewire::actingAs($writer)->test(PanelKit::class)->assertSuccessful()->html();
$dom = storefrontDom($html);
$ids = array_map(static fn (DOMNode $n): string => $n->nodeValue, iterator_to_array($dom->query('//*[@id]/@id')));
same([], array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)), 'Every id on the page is its own');
$row = static function (string $section, string $label) use ($dom): DOMElement {
    $rows = $dom->query('//div[@data-domains-row][contains(@id,"-'.$section.'-")][normalize-space(div[@class="domains-panel-row-k"])="'.$label.'"]');
    expect($rows->length === 1, 'One row '.$label.' in '.$section.': '.$rows->length);
    return $rows->item(0);
};
$q = static fn (DOMElement $in, string $path): DOMNodeList => $dom->query($path, $in);
$byId = static fn (string $id): ?DOMElement => $dom->query('//*[@id="'.$id.'"]')->item(0);

// The waiting edge comes from Checking only, and a change running in place shows its state where its control was.
expect($row('kit-rows-domain', 'Renewal')->hasAttribute('data-domains-wait') && !$row('kit-rows-domain', 'Transfer')->hasAttribute('data-domains-wait'), 'Checking rows wait, Under review rows do not');
$live = $row('kit-live-sent', 'Transfer lock');
same([true, 0, 'Checking'], [$live->hasAttribute('data-domains-wait'), $q($live, './/button')->length, trim($q($live, './/*[@class="domains-panel-row-a"]//*[@class="domains-panel-st"]')->item(0)?->nodeValue ?? '')], 'A live row waits and has no control');
same(['Check balance', 'Add funds'], array_map(static fn (DOMNode $n): string => trim($n->textContent), iterator_to_array($q($row('kit-rows-account', 'Balance'), './/*[@class="domains-panel-row-a"]//*[self::button or self::a]'))), 'Two native actions, in order');

// Blocked by the section notice: the controls stay, named and focusable, point to the notice and run nothing.
$notice = $dom->query('//div[@data-domains-row="notice"][contains(@id,"orders-paused")]')->item(0);
expect($notice !== null, 'The section notice is on the page');
foreach ([['row-blocked-ns', 'Edit Nameservers'], ['row-blocked-lock', 'Turn off Transfer lock']] as [$key, $name]) {
    $button = $dom->query('//div[@data-domains-row][contains(@id,"'.$key.'")]//button')->item(0);
    same(['true', $notice->getAttribute('id'), $name, false, false], [$button?->getAttribute('aria-disabled'), $button?->getAttribute('aria-describedby'), $button?->getAttribute('aria-label'), $button?->hasAttribute('wire:click'), $button?->hasAttribute('disabled')], 'A row blocked by the notice: '.$name);
}
expect($q($row('kit-blocked-registrar', 'Last sync'), './/button[@*[name()="wire:click"]]')->length === 1, 'The notice blocks only the rows that say so');
// A request that names a blocked action runs nothing, while an open action in the same section still runs.
$calls = [];
Illuminate\Support\Facades\Event::listen(Filament\Actions\Events\ActionCalling::class, static function (Action $action) use (&$calls): void { $calls[] = $action->getName(); });
$page = Livewire::actingAs($writer)->test(PanelKit::class);
foreach ([['kit_blocked_ns', 'kit-blocked.kit-blocked-registrar.row-blocked-ns'], ['kit_blocked_lock', 'kit-blocked.kit-blocked-registrar.row-blocked-lock'],
    ['kit_records', 'kit-blocked.kit-blocked-dns.row-host-records'], ['kit_transfers', 'kit-toggle.kit-toggle-orders.row-transfers'], ['kit_sync', 'kit-blocked.kit-blocked-registrar.row-last-sync']] as [$name, $path]) {
    $context = ['schemaComponent' => 'content.'.$path.'.'.substr($path, strrpos($path, '.') + 1).'-actions'];
    $runs = $name === 'kit_sync' ? [$name] : [];
    $calls = [];$page->call('mountAction', $name, [], $context)->set('mountedActions', []);
    same($runs, $calls, 'Clicked by name, only the open action runs: '.$name);
    // A client can also put the action in mountedActions and call it directly.
    $calls = [];$page->set('mountedActions', [['name' => $name, 'arguments' => [], 'context' => $context]])->call('callMountedAction')->set('mountedActions', []);
    same($runs, $calls, 'Called directly, only the open action runs: '.$name);
}
// Every staff instant on the kit converts in the browser; the done row keeps its confirmation time as a moment.
same(0.0, $dom->evaluate('count(//*[@data-domains-fact="date"][contains(@title," UTC")][not(.//time[@data-domains-time])])'), 'No staff instant on the kit is static text');
same(1.0, $dom->evaluate('count(.//*[@class="domains-panel-sub"]//time[@data-domains-time])', $row('kit-live-done', 'Transfer lock')), 'The confirmation sentence ends with a time that converts');
// A blocked link or Alpine action runs nothing either: no href and no handler but the one that cancels the click.
$go = Action::make('go')->url('https://registrar.example/domain/7');
$alpine = Action::make('copy')->alpineClickHandler('navigator.clipboard.writeText(\'x\')');
$blockedRow = Row::make('Registrar', $on)->actions($go, $alpine)->blocked('The registrar page opens once the domain is active.');
Schema::make(Livewire::actingAs($writer)->test(PanelKit::class)->instance())->components([$blockedRow])->getComponents(); // Places the row on a page, as a render does.
$blockedDom = storefrontDom($blockedRow->toEmbeddedHtml());
$controls = iterator_to_array($blockedDom->query('//*[@aria-disabled="true"]'));
$attrs = implode(' ', array_map(static fn (DOMElement $c): string => implode(' ', array_map(static fn (DOMAttr $a): string => $a->name.'='.$a->value, iterator_to_array($c->attributes))), $controls));
same([2, 0.0, false, false], [count($controls), $blockedDom->evaluate('count(//*[@href] | //*[@*[name()="wire:click"]])'), str_contains($attrs, 'registrar.example'), str_contains($attrs, 'clipboard')], 'A blocked URL or Alpine action keeps its button but has no link or handler');
// Blocked on its own: the reason is under the value and the control points to it.
foreach ([['kit-blocked-dns', 'Host records', 'Records can\'t be edited while DNS hosting is off.'], ['kit-toggle-orders', 'Transfers', 'Transfers can\'t be turned on without a Live registrar account.']] as [$section, $label, $why]) {
    $control = $q($row($section, $label), './/*[@class="domains-panel-row-a"]//button')->item(0);
    $reason = $byId((string) $control?->getAttribute('aria-describedby'));
    same(['true', false, true], [$control?->getAttribute('aria-disabled'), $control?->hasAttribute('wire:click'), str_starts_with(trim($reason?->textContent ?? ''), $why)], 'A blocked control points to its reason: '.$label);
}

// A switch and its word come from one bool; the switch is the page's action.
foreach ([['Registrations', 'true', 'On', true], ['Renewals', 'false', 'Off', true], ['Transfers', 'false', 'Off', false]] as [$label, $checked, $word, $runs]) {
    $r = $row('kit-toggle-orders', $label);$switch = $q($r, './/button[@role="switch"]')->item(0);
    same([$checked, $label, $word, $runs], [$switch?->getAttribute('aria-checked'), $switch?->getAttribute('aria-label'), trim($q($r, './/*[@class="domains-panel-plain"]')->item(0)?->nodeValue ?? ''), $switch?->hasAttribute('wire:click')], 'Switch '.$label);
}

// A setting with no control here says who sets it on the line under the value; the "?" keeps only what it means. A
// reader with the control gets no such line.
$tip = static fn (DOMElement $r): string => html_entity_decode((string) $q($r, './/*[contains(@class,"domains-help")]/@x-tooltip')->item(0)?->nodeValue, ENT_QUOTES);
$under = static fn (DOMElement $r): string => trim((string) $q($r, './/*[@data-piece="value"]//*[@data-piece="sub"]')->item(0)?->textContent);
$mode = $row('kit-rows-account', 'Mode');
same([true, true, false], [$under($mode) !== '', str_contains($tip($mode), 'Test and Live are separate'), $under($mode) !== '' && str_contains($tip($mode), $under($mode))], 'A read-only setting says who sets it on its own line under the value, and the "?" keeps only what it means');
same(['', '', 1], [$under($row('kit-rows-account', 'Reminder schedule')), $tip($row('kit-rows-account', 'Reminder schedule')), $q($row('kit-rows-account', 'Reminder schedule'), './/button[@aria-label="Edit Reminder schedule"]')->length], 'Staff who set reminders get the control and no who-sets-it line');
$savedDays = panelKitSavedDays($server);
expect($savedDays['before'] !== [] && $savedDays['after'] !== [], 'The kit has saved reminder days on both sides');
same($savedDays, panelKitShownDays((string) $q($row('kit-rows-account', 'Reminder schedule'), './/*[@data-domains-fact]')->item(0)?->nodeValue), 'Staff see each saved reminder day on its own side of the expiry');

// Staff without policy permission read the same row with no control.
$reader = staffUser([Staff::VIEW]);
$dom = storefrontDom(Livewire::actingAs($reader)->test(PanelKit::class)->assertSuccessful()->html());
$r = $dom->query('//div[@data-domains-row][normalize-space(div[@class="domains-panel-row-k"])="Reminder schedule"]')->item(0);
same([0, true], [$dom->query('.//button[@aria-label="Edit Reminder schedule"]', $r)->length, trim((string) $dom->query('.//*[@data-piece="value"]//*[@data-piece="sub"]', $r)->item(0)?->textContent) !== ''], 'A read-only reader sees no control and a line under the value saying who sets it');
// The kit is for staff on demo systems only.
Illuminate\Support\Facades\Auth::forgetUser();expect(!PanelKit::canAccess(), 'Signed-out readers can\'t open the kit');
Illuminate\Support\Facades\Auth::setUser(staffUser([]));expect(!PanelKit::canAccess(), 'Staff without the Domains view can\'t open the kit');

// A request notice with a timed retry: a typed body reaches the page as text, escaped once, and the retry stays a time.
$noticeRow = Paymenter\Extensions\Servers\Domains\Admin\Support\RequestRows::notice(['title' => 'Held for balance.', 'body' => new Illuminate\Support\HtmlString('Funds are <b>low</b> &amp; due.'), 'tone' => 'warning', 'next_try' => $at], State::Queued, null);
Schema::make($page->instance())->components([$noticeRow])->getComponents();
$notice = $noticeRow->toEmbeddedHtml();
expect(!str_contains($notice, '&lt;b&gt;') && !str_contains($notice, '&amp;amp;') && str_contains($notice, 'Funds are low &amp; due.'), 'A typed notice body is text, escaped once');
$noticeDom = storefrontDom($notice);
same($at, (new DateTimeImmutable($noticeDom->evaluate('string(//time/@datetime)')))->getTimestamp(), 'The next try preserves the retry instant');
// An inventory shows every value: a domain's five nameservers read as five names, never "and 2 more".
$five = ['ns1.example.net', 'ns2.example.net', 'ns3.example.net', 'ns4.example.net', 'ns5.example.net'];
same(implode(', ', $five), Paymenter\Extensions\Servers\Domains\Admin\Support\DomainRows::value(['value' => $five], null)->word, 'Nameservers and glue list every value');

// The preview and the list status build before a check or download has happened.
require __DIR__.'/Support/psl.php';
Paymenter\Extensions\Servers\Domains\Services\PslSchema::install();
$clock = new PslClock;
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
$list = new Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList($clock, new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static fn () => throw new LogicException('No download expected.')), new Paymenter\Extensions\Servers\Domains\Services\PslValidator(128, 5, 1));
$domain = new Paymenter\Extensions\Servers\Domains\Models\Domain(['domain' => 'fieldnote.example', 'user_id' => $writer->id, 'service_id' => 100, 'renewal_policy' => []]);
$errors = [];
foreach ([
    'import preview' => static fn (): array => Paymenter\Extensions\Servers\Domains\Admin\Support\AdoptionRows::sections($domain, [], '', null, 'Imports are off.'),
    'public suffix list' => static fn (): array => Paymenter\Extensions\Servers\Domains\Admin\Support\PslRows::sections($list),
] as $name => $build) {
    try { expect($build() !== [], 'The section is available: '.$name); }
    catch (Throwable $error) { $errors[] = $name.': '.$error->getMessage(); }
}
expect($errors === [], implode("\n", $errors));

// A switch keeps the additional account control supplied by the page.
$accountRow = Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarRows::row(['label' => 'Processing', 'value' => 'On'], [Action::make('account')->url('https://registrar.example/account')], $act('processing'));
Schema::make($page->instance())->components([$accountRow])->getComponents();
$accountDom = storefrontDom($accountRow->toEmbeddedHtml());
same([1.0, 1.0], [$accountDom->evaluate('count(//button[@role="switch"])'), $accountDom->evaluate('count(//a[@href="https://registrar.example/account"])')], 'The switch and account link are both usable');

$nativeSuccessful = true;
echo "Panel rows, settings rows and traces checked.\n";
