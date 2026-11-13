<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Panel values and heads show only states, plain words and facts; tones follow the reader and staff detail stays with staff.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
storefrontViews();
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Value};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State};
use Paymenter\Extensions\Servers\Domains\Presentation\Codes\InvoiceStatus;

config(['domains.time_zone' => 'America/Los_Angeles']);
$at = 1794503520; // Nov 12, 2026, 17:12 UTC
$read = static function (Value|string $html): DOMXPath { return storefrontDom($html instanceof Value ? $html->toHtml() : $html); };
$text = static fn (DOMXPath $dom, string $path): string => $dom->evaluate('normalize-space('.$path.')');
$fails = static function (Closure $make, string $class): bool { try { $make(); } catch (Throwable $e) { return $e instanceof $class; } return false; };

// A state: one word for both readers, the tone by reader, and the reason for both.
foreach ([[Reader::Staff, 'warn'], [Reader::Customer, 'prog']] as [$reader, $tone]) {
    $dom = $read(Value::state(State::UnderReview, $reader, 'This paid change waits for staff review.', 'domain-7'));
    same(['Under review', $tone, 'domain-7'], [$text($dom, '//*[@class="domains-panel-st"]'), $text($dom, '//*[@class="domains-panel-st"]/@data-domains-tone'), $text($dom, '//*[@class="domains-panel-st"]/@data-domains-record')], 'Under review by reader: '.$reader->name);
    same('This paid change waits for staff review.', $text($dom, '//*[@class="domains-panel-sub"]'), 'The reason reads the same for both');
}
// Staff get times and codes on one more line; customers never see it.
$failed = static fn (Reader $r): Value => Value::state(State::CouldntRead, $r, 'We couldn\'t reach the registrar.')->when($at)->detail(Fact::code('2400'));
$staff = $read($failed(Reader::Staff));
same(['err', 'Nov 12, 2026, 9:12 AM PST, 2400'], [$text($staff, '//*[@class="domains-panel-st"]/@data-domains-tone'), $text($staff, '//*[contains(@class,"domains-panel-extra")]')], 'Staff see when and the code');
same(['2026-11-12T17:12:00+00:00', 'Nov 12, 2026, 5:12 PM UTC', 1.0], [$text($staff, '//time/@datetime'), $text($staff, '//*[@data-domains-fact="date"]/@title'), $staff->evaluate('count(//time[@data-domains-time])')], 'A staff instant converts to the viewer\'s zone, with UTC on hover');
// A page moment: the browser shows the viewer's zone; without script it reads in the site's staff zone, named, never bare UTC.
$moment = $read(\Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet::moment($at, 'Checked')->toHtml());
same(['2026-11-12T17:12:00+00:00', 'Nov 12, 2026, 9:12 AM PST', 'Nov 12, 2026, 5:12 PM UTC'], [$text($moment, '//time/@datetime'), $text($moment, '//time'), $text($moment, '//span/@title')], 'A moment falls back to the named staff zone');
$customer = $read($failed(Reader::Customer));
same([0.0, 'We couldn\'t reach the registrar.'], [$customer->evaluate('count(//*[contains(@class,"domains-panel-extra")])'), $text($customer, '//*[@class="domains-panel-sub"]')], 'Customers read the reason with no time or code');
// A provider code we don't know reads Not recognized; the code is a staff detail.
$coded = InvoiceStatus::state('refunded', null, '2026-11-12');
same(['Not recognized', 'off', 'refunded'], [$text($read(Value::coded($coded, Reader::Staff)), '//*[@class="domains-panel-st"]'), $text($read(Value::coded($coded, Reader::Staff)), '//*[@class="domains-panel-st"]/@data-domains-tone'), $text($read(Value::coded($coded, Reader::Staff)), '//*[@data-domains-fact="code"]')], 'Unmapped code for staff');
same(0.0, $read(Value::coded($coded, Reader::Customer))->evaluate('count(//*[@data-domains-fact])'), 'Customers don\'t see provider codes');
same('Awaiting payment', $text($read(Value::coded(InvoiceStatus::state('pending', '2026-11-19', '2026-11-12'), Reader::Customer)), '//*[@class="domains-panel-st"]'), 'A mapped code shows its state');
same('warn', $text($read(Value::coded(InvoiceStatus::state('pending', '2026-11-19', '2026-11-12'), Reader::Customer)), '//*[@class="domains-panel-st"]/@data-domains-tone'), 'Customers must pay, so amber');

// Plain words and facts have no mark.
$plain = $read(Value::plain(Plain::Off));
same(['Off', 0.0], [$text($plain, '//*[@class="domains-panel-plain"]'), $plain->evaluate('count(//*[@data-domains-tone])')], 'Off is plain text with no tone');
$money = $read(Value::fact(Fact::money('1240'), '3 renew in November.'));
same(['USD 1,240.00', 'money', '3 renew in November.', 0.0], [$text($money, '//*[@class="domains-panel-fact"]'), $text($money, '//*[@class="domains-panel-fact"]/@data-domains-fact'), $text($money, '//*[@class="domains-panel-sub"]'), $money->evaluate('count(//*[@data-domains-tone])')], 'Money fact with a line under it');
same(['USD 12.3456', 'USD -5.50', null, null], [Fact::money('12.3456')?->text, Fact::money('-5.5')?->text, Fact::money('12,00'), Fact::money('5', 'usd')], 'Money reads currency code first, never rounds and refuses non-decimals');
// A schedule: the fact, then a label and its time under it.
$next = $read(Value::fact(Fact::entered('Weekly, Sunday 03:00'), 'Next', Fact::date($at, Reader::Staff)));
same(['Weekly, Sunday 03:00', 1.0, 'Next '.Fact::date($at, Reader::Staff)->text], [$text($next, '//*[@class="domains-panel-val"]/*[@class="domains-panel-fact"]'), $next->evaluate('count(//*[@class="domains-panel-sub"]//*[@data-domains-fact="date"])'), preg_replace('/\s+/', ' ', trim($text($next, '//*[@class="domains-panel-sub"]')))], 'A fact takes a label and its time, as the mock reads');
$bare = false; try { Value::fact(Fact::entered('Weekly'), null, Fact::date($at, Reader::Staff)); } catch (InvalidArgumentException) { $bare = true; }
expect($bare, 'A time under a fact follows a label');
// Late evening in the site's zone is already the next day in UTC: the customer's text and machine date name one day.
$late = Fact::date(new DateTimeImmutable('2026-11-12T01:00:00Z'), Reader::Customer);
same(['Nov 11, 2026', '2026-11-11'], [$late->text, $late->datetime], 'A customer date reads and encodes the same calendar day');
same(0.0, $read(Value::fact(Fact::date($at, Reader::Customer)))->evaluate('count(//time[@data-domains-time])') + $read(Value::fact(Fact::day('2027-03-04')))->evaluate('count(//time[@data-domains-time])'), 'Calendar dates stay as written, never converted');
same([['Nov 12, 2026', '2026-11-12', null], ['Mar 4, 2027', '2027-03-04']], [[Fact::date($at, Reader::Customer)->text, Fact::date($at, Reader::Customer)->datetime, Fact::date($at, Reader::Customer)->title], [Fact::day('2027-03-04 00:00:00')?->text, Fact::day('2027-03-04')?->datetime]], 'Customers read dates only; calendar days stay as stored');
same([null, '.com, .net, .org and 2 more', 'Enom TEST, NameSilo', 'ns1.example.net', null, null], [Fact::day('2027-02-30'), Fact::tlds(['io', 'org', 'dev', 'com', 'net'])?->text, Fact::names(['Enom TEST', ' NameSilo '])?->text, Fact::host('NS1.Example.net.')?->text, Fact::host('not a host'), Fact::names([])], 'Facts from typed data, null when there is nothing to show');

// Nameserver lists carry host metadata; account labels and mixed lists stay text.
same(['host', 'text', 'text', 'ns1.example.net, ns2.example.net'], [
    Fact::names(['ns1.example.net', 'ns2.example.net'])?->kind,
    Fact::names(['Enom TEST', 'NameSilo'])?->kind,
    Fact::names(['ns1.example.net', 'Enom TEST'])?->kind,
    Fact::names(['ns1.example.net', 'ns2.example.net'])?->text,
], 'Host lists preserve their values and identify hosts separately from account names');

// A list that is the point of its row keeps every entry (Names cuts at three), and an empty list is null.
$all = Fact::lines(['ns1.example.net', 'ns2.example.net', 'ns3.example.net', 'ns4.example.net', ' ']);
same([['ns1.example.net', 'ns2.example.net', 'ns3.example.net', 'ns4.example.net'], 'host', null], [$all?->items, $all?->kind, Fact::lines(['', ' '])], 'A list fact keeps every entry and is null when empty');
same('text', Fact::lines(['192.0.2.1', 'ns1.example.net'])?->kind, 'Mixed IP and nameserver entries remain text');

// A typed reason is shown as typed, even when it reads like a state word; nothing typed is null.
same(['Failed', 'entered', null], [Fact::entered(" Failed\n")?->text, Fact::entered('Checked with the customer')?->kind, Fact::entered('  ')], 'An entered fact is the typed line, null when empty');

// The boundary: no free text, no status column, no state word passed off as data or as a reason.
$model = (new class extends Model {})->forceFill(['name' => 'Enom TEST', 'status' => 'active', 'note' => null, 'enabled' => true]);
same(['Enom TEST', null], [Fact::attr($model, 'name')?->text, Fact::attr($model, 'note')], 'A model attribute, null when empty');
expect($fails(static fn () => Fact::attr($model, 'status'), InvalidArgumentException::class), 'A status column takes a State');
expect($fails(static fn () => Fact::attr($model, 'enabled'), InvalidArgumentException::class), 'A yes or no takes Plain');
expect($fails(static fn () => Fact::names(['Active']), AssertionError::class), 'Computed text that equals a state word fails');
// An unmapped provider code spelled like a word renders for both readers; only staff see the code.
$wordCode = InvoiceStatus::state('unpaid', null, '2026-11-12');
same(['Not recognized', 'unpaid'], [$text($read(Value::coded($wordCode, Reader::Staff)), '//*[@class="domains-panel-st"]'), $text($read(Value::coded($wordCode, Reader::Staff)), '//*[@data-domains-fact="code"]')], 'Staff see the code under Not recognized');
same(['Not recognized', 0.0], [$text($read(Value::coded($wordCode, Reader::Customer)), '//*[@class="domains-panel-st"]'), $read(Value::coded($wordCode, Reader::Customer))->evaluate('count(//*[@data-domains-fact])')], 'Customers read Not recognized with no code');
expect($fails(static fn () => Value::state(State::Failed, Reader::Staff, 'Not set'), AssertionError::class), 'A reason that equals a state word fails');
expect($fails(static fn () => Value::plain(Plain::On)->when(1), LogicException::class), 'Only a state has a staff line');
foreach ([Value::class, Fact::class] as $class) { expect(!(new ReflectionClass($class))->getConstructor()->isPublic(), 'No public constructor takes free text: '.$class); }
$escaped = $read(Value::state(State::Failed, Reader::Staff, '<b>x</b> broke.'));
same([0.0, '<b>x</b> broke.'], [$escaped->evaluate('count(//b)'), $text($escaped, '//*[@class="domains-panel-sub"]')], 'A reason is text, never markup');
// The Blade component renders the same value.
same($failed(Reader::Staff)->toHtml(), Blade::render('<x-domains::panel.value :value="$value" />', ['value' => $failed(Reader::Staff)]), 'The component tag and the PHP piece agree');

// Customer trace: a mark only where a step has a state. A step with no state has none, whatever its label or word says.
$trace = $read(Blade::render('<x-domains::customer.trace :steps="$steps" />', ['steps' => [
    ['label' => 'Invoice #7', 'state' => State::Paid], ['label' => 'Registration', 'state' => State::Checking],
    ['label' => 'Transfer', 'state' => State::Cancelled], ['label' => 'Cancelled', 'state' => null], ['label' => 'Active', 'word' => 'Skipped'],
]]));
same([['Invoice #7', 'ok', 1.0], ['Registration', 'prog', 1.0], ['Transfer', 'off', 1.0], ['Cancelled', '', 0.0], ['Active', '', 0.0]],
    array_map(static fn (DOMNode $li): array => [$trace->evaluate('normalize-space(./div/*[1])', $li), $trace->evaluate('string(@data-domains-tone)', $li), $trace->evaluate('count(./*[@class="domains-trace-mark"])', $li)],
        iterator_to_array($trace->query('//ol[@data-piece="trace"]/li'))), 'Customer trace marks follow the state, never the wording');

// Head: title with its tag, then the record's own state, facts and one link.
$head = Head::make('fieldnote.example', Reader::Staff)->domain()->tag(demo: false, test: true)->state(State::Active, 'domain-8')->fact('Expires', Fact::day('2027-03-04'))->link('Enom TEST', '/admin/registrar-account/3');
$title = $read($head->heading()->toHtml());
same('fieldnote.example', $text($title, '//*[@class="domains-panel-head-title"]'), 'The title is the record name');
expect(str_contains($head->heading()->toHtml(), '<wbr>') && str_contains($title->document->textContent, 'Test'), 'A domain title wraps after dots and carries its Test tag');
$line = $read($head->line()->toHtml());
same(['Active', 'ok', 'domain-8', 'Expires Mar 4, 2027', '/admin/registrar-account/3'], [$text($line, '//*[@class="domains-panel-st"]'), $text($line, '//*[@class="domains-panel-st"]/@data-domains-tone'), $text($line, '//*[@class="domains-panel-st"]/@data-domains-record'), $text($line, '//*[@class="domains-panel-head-fact"]'), $text($line, '//a/@href')], 'The head line');
same(0.0, $line->evaluate('count(//div|//p)'), 'The head line is inline, so it fits inside Filament\'s subheading');
same('prog', $text($read(Head::make('Invoice #100', Reader::Staff)->state(State::AwaitingPayment, 'invoice-100')->line()->toHtml()), '//*[@class="domains-panel-st"]/@data-domains-tone'), 'Head tone follows its reader');
same(null, Head::make('Settings', Reader::Staff)->line(), 'No line when there is nothing to say');
$nativeSuccessful = true; echo "Panel value passed.\n";
