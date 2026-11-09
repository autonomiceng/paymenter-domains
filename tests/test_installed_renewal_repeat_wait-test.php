<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

// A paid Enom renewal waiting out the 24-hour repeat refusal: its own reason, a fixed time, and the same facts on the
// staff request page and the customer domain page and list. Two native funded cycles are needed to reach the wait.
[$first, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-repeat-wait-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($first->id, $writer)->state, 'First annual renewal sends');
$state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($first->id)->state, 'First annual renewal is confirmed');
$completedAt = new DateTimeImmutable($first->fresh()->completed_at->format(DATE_ATOM));
$goes = $completedAt->modify('+24 hours')->setTimezone(new DateTimeZone('UTC'));
$clock->instant = $completedAt->modify('+6 hours');
activeCommerceFacts($clock->now(), $clock->now()->modify('+30 days'), $ports);
$observeRenewalCost($b);
$t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
$facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
$ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
    'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers(new Query($b, ['com'], 'renew'), Context::Staff)[0]->revision);
$invoice = $billing->invoice($d->id, $user, 2);
$second = Operation::where('invoice_id', $invoice->id)->sole();
App\Helpers\ExtensionHelper::addPayment($invoice, null, $second->amount);
$writes = $state->writes; Cache::flush();
same('queued', $engine->run($second->id, $writer)->state, 'Paid same-day renewal waits');
// Two later turns: the time stays the completion + 24 hours, never now + 24 hours.
foreach (['+3 hours', '+10 hours'] as $later) { $clock->instant = $completedAt->modify($later); Cache::flush(); $engine->run($second->id, $writer); }
$clock->instant = $completedAt->modify('+16 hours');
same($writes, $state->writes, 'The wait sends nothing');
$op = $second->fresh();

// Every check runs, so a red run lists everything that is missing.
$failed = [];
$check = static function (bool $ok, string $what) use (&$failed): void { if (!$ok) { $failed[] = $what; } };
$at = static fn (string $html): bool => str_contains($html, 'datetime="'.$goes->format('Y-m-d\TH:i:s').'+00:00"');
$text = static fn (string $html): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si', '', $html)), ENT_QUOTES)));
$staffWhy = 'Waiting: Enom refuses a second renewal of the same domain within 24 hours';
$customer = 'Nothing more to pay or do. The renewal goes to the registrar automatically at';

$check($op->safe_code === 'renewal_repeat_wait', 'reason: its own reason, not renewal_rate_limited (got '.$op->safe_code.')');
$check(($op->policy['renewal_execution']['eligible_at'] ?? null) !== null
    && (new DateTimeImmutable($op->policy['renewal_execution']['eligible_at']))->getTimestamp() === $goes->getTimestamp(),
    'time: stored once as the last completion + 24 hours');
$check(Labels::reason('renewal_rate_limited') === 'Not sent: our request limit reached. Try later.', 'ordinary rate limiting keeps its words');
$check(Labels::reason('renewal_repeat_wait') === $staffWhy, 'staff lists: the wait reason');

// Staff request page: one blue notice with why, the time in the viewer's zone, and that it goes by itself after checks.
staffPanel(); $staff = staffUser([Staff::VIEW]); Auth::setUser($staff);
$notice = RequestFacts::notice($op);
$check(($notice['tone'] ?? null) === 'info', 'staff notice: blue, not a warning');
$check(($notice['title'] ?? null) === $staffWhy.'.', 'staff notice: why it waits');
$page = Livewire\Livewire::actingAs($staff)->test(ViewOperation::class, ['record' => $op->id]);
$staffHtml = $page->html(); $staffText = $text($staffHtml);
$check(str_contains($staffText, $staffWhy.'.'), 'staff page: why it waits');
$check(str_contains($staffText, 'Paid. '.$customer), 'staff page: paid and nothing to do, same words as customers');
$check($at($staffHtml) && str_contains($staffText, 'after fresh checks.'), 'staff page: the fixed time, after fresh checks');
$check(!str_contains($staffText, 'request limit'), 'staff page: no rate-limit words');
$check(substr_count($staffText, $staffWhy) === 1, 'staff page: the reason once');

// Customer domain page and list: Paid, nothing to do, and when it goes. No amber, no Under review.
require __DIR__.'/Support/owner-pages.php'; // Replaces \$ports with the owner page ports.
$present = $owners->present($d->fresh());
$summary = (new DomainSummaries)->one($user, 'managed', $d->id, $clock->now()->getTimestamp());
$next = DomainPage::nextInvoice($present, $summary, null);
$check(($next['value'] ?? null) === 'Paid' && !isset($next['tone']), 'customer page: Paid, no tone');
app('view')->share('keys', array_fill_keys(['setup', 'recovery', 'code', 'renew', 'auto-renew', ...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS], str_repeat('a', 64)));
app('view')->share('errors', new Illuminate\Support\ViewErrorBag);
$showHtml = view('domains::show', ['domain' => $present, 'summary' => $summary])->render(); $showText = $text($showHtml);
$dom = new DOMDocument; @$dom->loadHTML($showHtml);
$card = (new DOMXPath($dom))->query('//section[@id="renewal"]//dt[normalize-space(text())="Next invoice"]/following-sibling::dd[1]')->item(0);
$check($card !== null && str_starts_with(trim(preg_replace('/\s+/', ' ', $card->textContent)), 'Paid '.$customer), 'customer page: Paid, nothing to do, goes automatically');
$check($at($showHtml), 'customer page: the fixed time in the viewer\'s zone');
$check(substr_count($showText, $customer) === 1, 'customer page: said once');
$row = DomainListRow::renewal($summary);
$listHtml = view('domains::index', ['domains' => new Illuminate\Pagination\LengthAwarePaginator([$summary], 1, 25)])->render(); $listText = $text($listHtml);
$check(($row['note'] ?? null) === 'Next invoice: Paid. '.$customer && str_contains($listText, 'Next invoice: Paid. '.$customer), 'customer list: Paid, nothing to do, goes automatically');
$check($at($listHtml), 'customer list: the fixed time in the viewer\'s zone');
$check(!str_contains($showText.$listText, 'Under review') && !str_contains($showText.$listText, 'request limit'), 'customer: no review or limit words');
expect($failed === [], 'Repeat-renewal wait facts missing: '.implode('; ', $failed));

// Turning off future renewal invoices preserves this already paid cycle, including its fixed time.
$policy=$d->fresh()->renewal_policy;$d->update(['renewal_policy'=>[...$policy,'owner_auto_renew'=>false]]);
$offSummary=(new DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
same($goes->format(DATE_ATOM),DomainListRow::renewal($offSummary)['at'],'Off list retains the exact paid wait instant');
$offHtml=view('domains::show',['domain'=>$owners->present($d->fresh()),'summary'=>$offSummary])->render();
expect($at($offHtml)&&str_contains(strip_tags($offHtml),$customer),'Off detail retains paid cycle timing and assurance');
$d->update(['renewal_policy'=>$policy]);
// When the time comes it sends by itself, and the wait words leave every page.
$clock->instant = $goes; Cache::flush();
$state->expiry = '10/18/2028 5:00:00 AM'; $state->day = '10/18/2028'; $state->years = 2; $state->after = '2030-10-18'; $state->order = '9003'; $state->charge = '16.00';
same('pending', $engine->run($second->id, $writer)->state, 'At the stored time it sends by itself');
same($writes + 1, $state->writes, 'Exactly one send');
expect(!str_contains((string) (RequestFacts::notice($second->fresh())['title'] ?? ''), 'Enom refuses'), 'No wait notice once sent');
same([], array_intersect_key(DomainPage::nextInvoice($owners->present($d->fresh()), null, null) ?? [], ['at' => true]), 'Customer page drops the time once sent');
echo "Paid repeat renewal waits with its own reason and a fixed time on staff and customer pages\n";
$nativeSuccessful = true;
