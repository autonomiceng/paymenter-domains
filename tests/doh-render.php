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
    $test = \Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\DnsResolver::class);
    if ($check) { $test->callAction('check'); }
    if ($modal !== null) {
        $test->mountAction('configure');
        if ($modal === 'invalid') { $test->setActionData(['url' => 'http://resolver-placeholder.org', 'ip' => '127.0.0.1'])->callMountedAction()->assertHasActionErrors(['url', 'ip']); }
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
    file_put_contents($out.'/staff-'.$name.'.html', $html);
};
$emit('missing'); $emit('missing-checked', true); $emit('configure', false, 'configure'); $emit('configure-invalid', false, 'invalid'); $settings->save(\Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint::URL, \Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint::IP);
$emit('configured');
$response = ['status' => 200, 'body' => '{"Status":0,"Question":[{"name":"example.com","type":1}],"Answer":[{"name":"example.com","type":1,"TTL":60,"data":"192.0.2.1"}]}']; $emit('working', true);
$response = new \Paymenter\Extensions\Servers\Domains\Dns\DohFailure('timeout'); $emit('failed', true);
$response = ['status' => 200, 'body' => '{"Status":2,"Question":[{"name":"example.com","type":1}]}']; $emit('servfail', true);
// The public-check status shares this stock page; no request or queue execution on render.
(new \Paymenter\Extensions\Servers\Domains\Domains([]))->installed();
$emit('public-disabled');
$clock=(object)['now'=>1800000000];
$work=new \Paymenter\Extensions\Servers\Domains\Services\WatchWork(new \Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null),true,fn()=>$clock->now);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,$work);$emit('public-queue-configuration');
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>180]);$emit('public-waiting');
\Illuminate\Support\Facades\DB::table('domains_watch_schedule')->insert(['scope'=>'dispatch','due_at'=>$clock->now,'progress_at'=>$clock->now-600,'data'=>json_encode(['started_at'=>$clock->now-86400])]);$emit('public-overdue');
\Illuminate\Support\Facades\DB::table('domains_watch_schedule')->where('scope','dispatch')->update(['progress_at'=>$clock->now]);
$id=\Illuminate\Support\Facades\DB::table('domains_watch_names')->insertGetId(['user_id'=>$staff->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),'state'=>'active','notice_preferences'=>'{}']);
\Illuminate\Support\Facades\DB::table('domains_watch_work')->insert(['watch_id'=>$id,'kind'=>'expiry','due_at'=>$clock->now,'attempts'=>1,'watch_revision'=>1]);
\Illuminate\Support\Facades\DB::table('domains_watch_schedule')->insert(['scope'=>'health_registry','cursor'=>$clock->now-86400]);$emit('public-failing');
\Illuminate\Support\Facades\DB::table('domains_watch_work')->update(['attempts'=>0,'due_at'=>$clock->now+86400]);$emit('public-current');
echo "Stock resolver and public-check status states rendered.\n"; $nativeSuccessful = true;
