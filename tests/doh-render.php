<?php
// Disposable stock staff renderer. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/doh-settings-test.php'; $nativeSuccessful = false;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
$out = $argv[1] ?? ''; expect(is_dir($out), 'Output directory required'); $setRights($rights);
$css = file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$emit = static function (string $name, bool $check = false, ?string $modal = null) use ($out, $css, $staff): void {
    session()->forget(['filament.notifications', 'filament.livewire.notifications']);
    $test = \Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings::class);
    if ($check) { $test->callAction('check'); }
    if ($modal !== null) {
        $test->mountAction('configure');
        if ($modal === 'invalid') { $test->setActionData(['url' => 'http://resolver.example.org', 'ip' => '127.0.0.1'])->callMountedAction()->assertHasActionErrors(['url', 'ip']); }
        $test->call('$refresh');
    }
    $html = Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>', ['page' => $test->instance(), 'body' => $test->html().($test->effects['partials']['action-modals.0'] ?? '')]);
    if ($modal !== null) {
        foreach ($test->instance()->mountedActions[0]['data'] as $key => $value) {
            $html = str_replace('wire:model="mountedActions.0.data.'.$key.'"', 'value="'.htmlspecialchars($value, ENT_QUOTES).'" wire:model="mountedActions.0.data.'.$key.'"', $html);
        }
        $html = str_replace('class="fi-modal fi-absolute-positioning-context"', 'class="fi-modal fi-absolute-positioning-context fi-modal-open"', $html);
    }
    $html = preg_replace('~<script\b[^>]*>.*?</script>~si', '', $html); $html = preg_replace('~<link\b[^>]*>~si', '', $html);
    $html = str_replace('x-cloak="-lg"', '', $html); $html = str_replace('class="fi-main-ctn"', 'style="display:flex;opacity:1" class="fi-main-ctn"', $html);
    $html = preg_replace('~src="https?://[^\"]+"~', 'src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"', $html);
    $policy = '<meta http-equiv="Content-Security-Policy" content="default-src \'self\' data:; style-src \'self\' \'unsafe-inline\'; font-src \'self\' data:; img-src \'self\' data:; script-src \'none\'; connect-src \'none\'; form-action \'none\'">';
    $html = str_replace('</head>', $policy.'<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>', $html);
    writeRenderArtifact($out.'/staff-'.$name.'.html', $html);
};
$emit('missing'); $settings->save(\Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint::URL, \Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint::IP);
$emit('configure', false, 'configure'); $emit('configure-invalid', false, 'invalid'); $emit('configured');
$response = ['status' => 200, 'body' => '{"Status":0,"Question":[{"name":"example.com","type":1}],"Answer":[{"name":"example.com","type":1,"TTL":60,"data":"192.0.2.1"}]}']; $emit('working', true);
$response = new \Paymenter\Extensions\Servers\Domains\Dns\DohFailure('timeout'); $emit('failed', true);
$response = ['status' => 200, 'body' => '{"Status":2,"Question":[{"name":"example.com","type":1}]}']; $emit('servfail', true);
// Monitoring notices render on the Domain monitoring list: staff-navigation-render.php.
echo "Stock resolver states rendered.\n"; $nativeSuccessful = true;
