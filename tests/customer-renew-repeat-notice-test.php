<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/renewal-runtime.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

// A second Enom renewal inside 24 hours says it will wait, and until when, before the invoice is made.
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
require __DIR__.'/Support/owner-pages.php';
$writes = [$state->writes, count($state->calls), Operation::count()];
$present = $owners->present($d->fresh());
same($goes->getTimestamp(), isset($present->renewNow['repeat_at']) ? (new DateTimeImmutable($present->renewNow['repeat_at']))->getTimestamp() : null,
    'Renew now knows when a new renewal could go: the last completion + 24 hours');
app('view')->share('keys', array_fill_keys(['setup', 'recovery', 'code', 'renew', 'auto-renew', ...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS], str_repeat('a', 64)));
app('view')->share('errors', new Illuminate\Support\ViewErrorBag);
$html = view('domains::show', ['domain' => $present, 'summary' => null])->render();
$dialog = storefrontDom($html)->query('//dialog[@id="edit-renew"]//*[@data-renewal-repeat]')->item(0);
$words = $dialog ? trim(preg_replace('/\s+/', ' ', $dialog->textContent)) : '';
expect(str_starts_with($words, 'The registrar accepts one renewal of a domain every 24 hours. If you renew now, the renewal goes to the registrar automatically at'),
    'The Renew dialog says why it would wait before Create invoice: '.$words);
expect($dialog !== null && str_contains($dialog->ownerDocument->saveHTML($dialog), 'datetime="'.$goes->format('Y-m-d\TH:i:s').'+00:00"'), 'The dialog gives the fixed time in the viewer\'s zone');
same($writes, [$state->writes, count($state->calls), Operation::count()], 'Showing the wait sends and creates nothing');
// A day later the dialog says nothing about a wait.
$clock->instant = $completedAt->modify('+25 hours');
expect(!isset($owners->present($d->fresh())->renewNow['repeat_at']), 'No wait once 24 hours have passed');
$nativeSuccessful = true; echo "Renew dialog explains the repeat wait passed.\n";
