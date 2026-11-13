<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the panel kit page as staff who set policy and as read-only staff, in the stock theme or a host panel's skin; no public I/O.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
require __DIR__.'/Support/panel-render.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PanelKit, PanelKitHistory, PanelKitList};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$out = renderOutput($argv[1] ?? null);
panelKitFixture();
panelTheme((string) getenv('PAYMENTER_TEST_FIXTURE'));
foreach (['panel-kit' => [Staff::VIEW, Staff::POLICY], 'panel-kit-read-only' => [Staff::VIEW]] as $name => $permissions) {
    Auth::setUser($user = staffUser($permissions));
    $kit = Livewire::actingAs($user)->test(PanelKit::class)->assertSuccessful();
    $list = Livewire::actingAs($user)->test(PanelKitList::class)->assertSuccessful();
    $history = Livewire::actingAs($user)->test(PanelKitHistory::class)->assertSuccessful();
    writePanelPage($out.'/'.$name.'.html', $kit, $list->html().$history->html());
    if ($name === 'panel-kit') {
        writePanelPage($out.'/panel-kit-list.html', $list);
        writePanelPage($out.'/panel-kit-history.html', $history);
    }
}
$nativeSuccessful = true;
echo "Panel kit rendered.\n";
