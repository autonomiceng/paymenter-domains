<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';

use Illuminate\Support\Facades\DB;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{SetUpSales,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;

extract(commerceFixture(true));
staffSalesFixture();
tldPanel();
pinStaffClock($now);
[$target, $other] = $tlds;
$staff = staffUser(array_keys(Staff::permissions()));
$viewer = staffUser([Staff::VIEW]);
$action = 'set_up_sales';
$custom = ['pricing' => 'custom', 'mode' => 'markup_amount', 'markup' => '1.20', 'round_up' => 'half'];
$reason = ['reason' => 'Set the reviewed domain prices.'];
$reviewKeyCount = static fn() => DB::table(Review::KEYS)->count();

$detail = Livewire::actingAs($viewer)->test(ViewTld::class, ['record' => $target->id]);
$detail->assertActionHidden($action);
$detailUrl = TldResource::getUrl('index').'/set-up-sales?record='.$target->id;
$detail = Livewire::actingAs($staff)->test(ViewTld::class, ['record' => $target->id]);
expect(str_contains($detail->html(), $detailUrl), 'The numeric TLD links to the dedicated setup page');
Livewire::actingAs($viewer)->withQueryParams(['record' => $target->id])->test(SetUpSales::class)->assertForbidden();
$singlePage = static fn() => Livewire::actingAs($staff)->withQueryParams(['record' => $target->id])->test(SetUpSales::class);
$detail = $singlePage()->call('mountAction', 'cancel')
    ->assertRedirect(TldResource::getUrl('view', ['record' => $target->id]).'#set-up-sales');
$detail = $singlePage()->assertSeeHtml('wire:model.live="draft.server"')
    ->assertSeeHtml('wire:model.live="draft.register"')->assertSeeHtml('wire:model.live="draft.pricing"');
$initialKeys = $reviewKeyCount();
$detail->set('draft', [...$detail->get('draft'), ...$custom])->call('reviewChanges')->assertHasNoErrors();
$single = $detail->get('review.rows');
same(['com'], array_keys($single), 'The numeric entry reviews its own suffix');
same('26.50', $single['com']['prices']['register:3']['after'], 'Review shows the calculated three-year amount');
$detail->assertSee('$26.50');
same($initialKeys, $reviewKeyCount(), 'Opening and reviewing the detail writes nothing');

$detail->set('draft.markup', '2.00')->set('confirmation', $reason)->call('save');
same([], $detail->get('review'), 'A changed pricing field invalidates the review');
same($initialKeys, $reviewKeyCount(), 'The changed draft writes nothing');
$detail->call('reviewChanges')->call('mountAction', 'cancel')->assertActionMounted('cancel');
$detail->unmountAction()->assertSet('draft.markup', '2.00');
$detail->callAction('cancel')->assertRedirect(TldResource::getUrl('view', ['record' => $target->id]).'#set-up-sales');
same([], $detail->get('review'), 'Discarding clears the review');
same($initialKeys, $reviewKeyCount(), 'Discarding never consumes a review');

$assertRow = static function ($page, $tld): void {
    $url = TldResource::getUrl('view', ['record' => $tld->id]);
    $dom = new DOMDocument();
    $dom->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//tr/td[contains(@class,"domains-sell-pin")]/a[@href="'.$url.'"]');
    same(1, $links->length, 'The suffix link opens the exact detail for record '.$tld->id);
    $link = $links->item(0);
    same('.'.$tld->tld, trim($link->textContent), 'The link names the suffix');
    expect($link->getAttribute('tabindex') !== '-1', 'The suffix remains a keyboard stop');
    $row = $links->item(0)->parentNode->parentNode;
    expect($xpath->query('./td/a[@href="'.$url.'"][@tabindex="-1"]', $row)->length > 0,
        'Plain row cells navigate to the same detail with native record links');
    same(1, $xpath->query('./td/input[@type="checkbox"][not(ancestor::a)]', $row)->length,
        'The row checkbox remains outside every navigation link');
};
$allAccounts = Livewire::actingAs($staff)->test(WhatYouSell::class, ['allTlds' => true]);
$assertRow($allAccounts, $target);
$accountScope = Livewire::actingAs($staff)->withQueryParams(['account' => $other->server_id, 'filters' => ['for_sale' => ['isActive' => false]]])
    ->test(WhatYouSell::class, ['allTlds' => true])->assertSet('account', $other->server_id)->assertCountTableRecords(1);
$assertRow($accountScope, $other);

$absent = $other->replicate();
$absent->tld = 'net';
$absent->save();
$catalogueParams = ['search' => 'e']; // A later launch uses an unfiltered list.
$invalid = Livewire::actingAs($staff)->withQueryParams($catalogueParams)->test(WhatYouSell::class, ['allTlds' => true])
    ->selectTableRecords([$target->id, $absent->id])->callAction(TestAction::make($action)->table()->bulk());
expect(!isset($invalid->effects['redirect']), 'A substituted key outside the filtered catalogue cannot launch setup');

$bulk = TestAction::make($action)->table()->bulk();
$catalogue = Livewire::actingAs($staff)->withQueryParams([])->test(WhatYouSell::class, ['allTlds' => true])
    ->selectTableRecords([$target->id, $absent->id])->callAction($bulk);
$destination = $catalogue->effects['redirect'] ?? null;
expect(is_string($destination), 'The bulk action opens the dedicated setup page');
parse_str(parse_url($destination, PHP_URL_QUERY), $query);
expect(isset($query['selection']), 'The exact catalogue selection travels in the handoff');

$anotherStaff = staffUser(array_keys(Staff::permissions()));
Livewire::actingAs($anotherStaff)->withQueryParams($query)->test(SetUpSales::class)->assertForbidden();
$setup = Livewire::actingAs($staff)->withQueryParams($query)->test(SetUpSales::class);
$setup->set('draft', [...$setup->get('draft'), 'server' => $target->server_id, ...$custom])->call('reviewChanges')->assertHasNoErrors();
same(['com' => 'ready', 'net' => 'blocked'], array_map(static fn($row) => $row['status'], $setup->get('review.rows')),
    'The full selection is reviewed, including a suffix missing from the target account');
same([], $setup->get('review.rows.net.prices'), 'Missing prices do not produce a draft amount');

DB::table('domains_tlds')->where('id', $absent->id)->update(['tld' => 'org']);
$setup->set('confirmation', $reason)->call('save');
same([], $setup->get('review'), 'A changed source record invalidates the review');
same($initialKeys, $reviewKeyCount(), 'Selection substitution writes nothing');
DB::table('domains_tlds')->where('id', $absent->id)->update(['tld' => 'net']);

$setup->call('reviewChanges')->assertHasNoErrors();
DB::table('domains_prices')->where('server_id', $target->server_id)->where('tld', 'com')->where('action', 'renew')->increment('revision');
$setup->call('save')->assertHasNoErrors();
same([], $setup->get('review'), 'A price revision requires a fresh review');
$setup->assertSet('draft.markup', '1.20')->assertSet('confirmation.reason', $reason['reason']);
same($initialKeys, $reviewKeyCount(), 'A stale review writes nothing');
$setup->call('reviewChanges')->assertHasNoErrors();
$setup->call('save')->assertHasNoErrors();
same(['com'], $setup->get('outcome.rows'), 'Confirmation saves exactly the reviewed ready suffix');
same(['net'], array_keys($setup->get('outcome.failures')), 'The blocked suffix is in the actual outcome');
same('26.50', $setup->get('outcome.outcomes.com.prices.register:3.after'), 'The saved result uses the writer amount');
same($initialKeys + 1, $reviewKeyCount(), 'The confirmed review is used once');
$facts = OfferState::decode($target->fresh()->catalogue_facts);
$facts['requiresNameservers'] = true;
DB::table('domains_tlds')->where('id', $target->id)->update(['catalogue_facts' => OfferState::json($facts)]);
$refusal = $singlePage();
$refusal->set('draft', [...$refusal->get('draft'), ...$custom])->call('reviewChanges')->assertHasNoErrors();
same('blocked', $refusal->get('review.rows.com.status'), 'Known required nameservers refuse sales setup');
same('26.50', $refusal->get('review.rows.com.prices.register:3.after'), 'The refused row has a calculated draft');
$refusal->assertSeeHtml('aria-label="Draft prices for .com"')->assertSee('$26.50')->assertDontSeeHtml('wire:click="save"');
$refusal->set('confirmation', $reason)->call('save')->assertSet('outcome', null);
same($initialKeys + 1, $reviewKeyCount(), 'The refused draft cannot be confirmed');
same(0, $reads->calls, 'Staff setup uses saved prices and terms');
$nativeSuccessful = true;
echo "Single and bulk sales setup pages require a matching review.\n";
