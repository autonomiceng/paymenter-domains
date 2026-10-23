<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-preview.php';
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{AdoptionPreview, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\Review;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionReader, ObservationSchema};
extract(adoptionFixture()); ObservationSchema::install(); staffPanel();
app()->instance(AdoptionReader::class, $reader);
// Reviewed-conversion shape, inserted only in the disposable fixture. No runtime promotion seam exists.
$id = adoptionPendingFixture($service, $binding)->id;
$viewOnly = staffUser([Staff::VIEW]);
Livewire::actingAs($viewOnly)->test(AdoptionPreview::class, ['record' => $id])->assertForbidden();
// Import domain is offered once, in the notice that explains it.
$adoptionUrl = \Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('adoption', ['record' => $id]);
Livewire::actingAs($staff)->test(ViewDomain::class, ['record' => $id])->assertSeeHtml('href="'.$adoptionUrl.'"')->assertActionDoesNotExist('adoptionPreview');
Livewire::actingAs($viewOnly)->test(ViewDomain::class, ['record' => $id])->assertSuccessful()->assertDontSeeHtml('href="'.$adoptionUrl.'"');
$view = Livewire::actingAs($staff)->test(AdoptionPreview::class, ['record' => $id])->assertSuccessful()
    ->assertSee('Adoption is not enabled yet')->assertSee('No fresh preview yet.')->assertSee('TEST')->assertActionDisabled('confirm')
    ->assertSee(AdoptionPreview::UNAVAILABLE)->assertSee('This is a TEST account rehearsal; it cannot verify a live registration.');
same(AdoptionPreview::UNAVAILABLE, $view->instance()->getAction('confirm')->getTooltip(), 'Disabled confirm tooltip explains why');
$view->mountAction('preview'); expect(str_contains((string) $view->instance()->getMountedAction()->getModalDescription(), 'The preview charges nothing and adopts nothing.'), 'Preview modal states it charges and adopts nothing'); $view->unmountAction();
foreach (['Saved environment', 'Source expiry', 'live holding', 'Adoption remains disabled', 'mapping'] as $jargon) { expect(!str_contains($view->html(), $jargon), 'Staff preview has no internal jargon '.$jargon); }
$initialHtml = $view->html();
same(0, count($wire->calls), 'GET page and modal rendering never call registrar');
$view->callAction('preview', data: ['reason' => '', 'do_not_renew' => false])->assertHasActionErrors(['reason' => 'required']);
same(0, count($wire->calls), 'Required reason prevents wire');
$before = [$service->fresh()->getAttributes(), Domain::findOrFail($id)->getRawOriginal(), App\Models\Invoice::count()];
$view = Livewire::actingAs($staff)->test(AdoptionPreview::class, ['record' => $id]);
$view->callAction('preview', data: ['reason' => 'Reviewed synthetic service mapping', 'do_not_renew' => true])->assertHasNoActionErrors()
    ->assertSee('Rehearsal checks passed. A TEST account cannot verify a live registration.')->assertSee('None shown because do not renew was requested')->assertDontSee('renewal off')->assertSee('Expiry on file')->assertSee('Oct 20, 2027')->assertSee($owner->name)->assertSee('Oct 20, 2027 06:14 UTC')->assertSee('Adoption preview')->assertSee('USD')->assertSee('Requested for review; no cancellation has been created.')->assertActionDisabled('confirm')->assertDontSee('(UTC date)');
same(4, count($wire->calls), 'Only explicit action makes bounded named readback'); same(1, Adoption::count(), 'Preview retains evidence');
$view->callAction('confirm'); same('adoption_pending', Domain::findOrFail($id)->status, 'Disabled confirm cannot promote a holding');
same($before, [$service->fresh()->getAttributes(), Domain::findOrFail($id)->getRawOriginal(), App\Models\Invoice::count()], 'Staff preview changes no native/holding/invoice state');
$html = $view->html();
foreach (['synthetic-secret', 'account-enom', 'provider_domain_id', 'request_digest', 'account_fingerprint', 'domains_credentials'] as $private) { expect(!str_contains($html, $private), 'HTML/Livewire payload excludes '.$private); }
// Render every safe refusal through the actual page's native Filament schema, independently of provider fixtures.
$page = $view->instance(); $details = $page->review['details'];
$rendered = [];
foreach (Review::LABELS as $code => $label) {
    $sample = $code === 'ready' ? $details : [...$details, 'expiry' => null, 'renewal_due' => null];
    if (in_array($code, ['permission_denied', 'mapping_unconfirmed', 'account_unconfirmed', 'competing_holding', 'unresolved_debt', 'changed', 'price_unconfirmed', 'native_expiry_present', 'preview_failed'], true)) { $sample = []; }
    $projection = (new Review($code, $sample))->jsonSerialize(); $page->review = $projection;
    $rendered[$code] = $page->infolist(Schema::make($page))->toHtml();
    $shown = $code === 'ready' ? 'Rehearsal checks passed. A TEST account cannot verify a live registration.' : $projection['message'];
    expect(str_contains(html_entity_decode(strip_tags($rendered[$code])), $shown), 'Native rendered refusal label: '.$code);
    expect(!str_contains($rendered[$code], 'synthetic-secret'), 'Refusal rendering contains no credential');
}
$page->review = (new Review('ready', [...$details, 'environment' => 'live', 'test_only' => false]))->jsonSerialize();
$rendered['live-ready'] = $page->infolist(Schema::make($page))->toHtml();
expect(str_contains(strip_tags($rendered['live-ready']), 'Live') && str_contains(strip_tags($rendered['live-ready']), Review::LABELS['ready']), 'Synthetic live badge and eligible result rendered');
expect(!str_contains(strip_tags($rendered['live-ready']), 'TEST account rehearsal'), 'Live preview has no TEST note');
foreach (['live holding', '2027-10-20', 'Source expiry'] as $old) { expect(!str_contains(strip_tags($rendered['ready']), $old), 'Rendered preview omits '.$old); }
$page->review = (new Review('ready', [...$details, 'do_not_renew' => false, 'requested_do_not_renew' => false, 'renewal_due' => '2027-10-13']))->jsonSerialize();
$rendered['ready-renewing'] = $page->infolist(Schema::make($page))->toHtml();
expect(str_contains(strip_tags($rendered['ready-renewing']), 'Oct 13, 2027'), 'Native page renders the default seven-day renewal due date');
$page->reviewReason = '<script>alert(1)</script>';
expect(!str_contains($page->infolist(Schema::make($page))->toHtml(), '<script>alert(1)</script>'), 'Staff reason escaped by native entry');
$out = $argv[1] ?? null;
if ($out !== null) {
    expect(is_dir($out) && is_writable($out), 'Writable external render output required');
    $css = file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css'); $livewireStyles = \Illuminate\Support\Facades\Blade::render('@livewireStyles');
    preg_match_all('/<style\b[^>]*>.*?<\/style>/s', \Illuminate\Support\Facades\Blade::render('@filamentStyles'), $palette);
    $livewireStyles .= implode('', $palette[0]);
    foreach ($rendered as $code => $body) {
        foreach ([1440, 1024] as $width) { foreach (['dark', 'light'] as $mode) {
            $background = $mode === 'dark' ? '#09090b;color:#fafafa' : '#ffffff;color:#18181b';
            $count = 0; $component = preg_replace_callback('/<section\b[^>]*class="[^"]*\bfi-section\b[^"]*"[^>]*>.*?<\/section>/s', fn () => $body, $html, 1, $count);
            same(1, $count, 'Exactly one native page section replaced for state capture');
            file_put_contents($out.'/'.$code.'-'.$width.'-'.$mode.'.html', '<!doctype html><html class="'.$mode.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$css.'</style>'.$livewireStyles.'</head><body style="background:'.$background.'"><main style="width:'.$width.'px;max-width:100%;margin:auto;padding:2rem">'.$component.'</main></body></html>');
        } }
    }
    file_put_contents($out.'/eligible-component.html', $html); file_put_contents($out.'/initial-component.html', $initialHtml);
    foreach (['initial' => $initialHtml, 'eligible' => $html] as $kind => $component) { foreach ([1440, 1024] as $width) { foreach (['dark', 'light'] as $mode) {
        $background = $mode === 'dark' ? '#09090b;color:#fafafa' : '#ffffff;color:#18181b';
        file_put_contents($out.'/'.$kind.'-page-'.$width.'-'.$mode.'.html', '<!doctype html><html class="'.$mode.'"><head><meta charset="utf-8"><style>'.$css.'</style>'.$livewireStyles.'</head><body style="background:'.$background.'"><main style="max-width:80rem;margin:auto;padding:2rem">'.$component.'</main></body></html>');
    } } }
    $index = []; $strings = [];
    foreach (array_merge(glob($out.'/*-dark.html'), glob($out.'/*-light.html')) as $file) {
        $text = file_get_contents($file); $index[] = ['html' => basename($file), 'html_sha256' => hash('sha256', $text)];
        $strings[basename($file)] = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/s', '', $text)), ENT_QUOTES|ENT_HTML5)));
    }
    file_put_contents($out.'/capture-index.json', json_encode(['capture_status' => 'rendered HTML; PNG capture pending', 'records' => $index], JSON_PRETTY_PRINT));
    file_put_contents($out.'/rendered-strings.json', json_encode($strings, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    file_put_contents($out.'/strings.json', json_encode(['labels' => Review::LABELS, 'confirm' => AdoptionPreview::UNAVAILABLE, 'test' => 'TEST (rehearsal only)', 'test_ready' => (new Review('ready', ['test_only' => true]))->jsonSerialize()['message'], 'capture_status' => 'rendered HTML; PNG capture pending'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
$roleBefore = $staff->role->permissions;
$bindingRow = DB::table('domains_accounts')->where('server_id', $binding->serverId)->first();
DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['environment' => 'invalid']);
$view->callAction('preview', data: ['reason' => 'New reason for unavailable account', 'do_not_renew' => false])->assertHasNoActionErrors();
same('New reason for unavailable account', $view->instance()->reviewReason, 'Early refusal cannot retain a previous reason');
same('account_unconfirmed', $view->instance()->review['code'], 'Unavailable account label is specific');
DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['environment' => $bindingRow->environment]);
new \Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext($staff->id, str_repeat('é', 500));
expect($view->instance()->getRelationManagers() === [], 'Adoption page omits unrelated request history');
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode([Staff::VIEW])]);
$view->call('$refresh')->assertForbidden();
$nativeSuccessful = true;
echo "Staff adoption preview native HTML, permissions and disabled confirmation passed\n";
