<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
if (!getenv('DOMAINS_PANEL_SETUP') || !getenv('SKIN_RENDER_MARKER')) { throw new RuntimeException('Run through skin-render.'); }
require __DIR__.'/staff-monitoring.php';
require_once __DIR__.'/site-dns-default.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{EditConfiguration, ListConfiguration, ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;

$off = DB::transaction(fn () => WatchProfile::create(['name'=>'Previous hosting template','nameservers'=>['ns1.old.test','ns2.old.test'],
    'edited_by'=>$manager->id,'reason'=>'Synthetic former template']));
config(['domains.site_dns_default.nameservers'=>$profile->nameservers]);
siteDnsFixture($profile->id);
$manager->role->update(['permissions'=>[...$manager->role->permissions,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY,
    \Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::MANAGE]]);
\Paymenter\Extensions\Servers\Domains\Admin\Support\DialogFooter::boot();
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
foreach (['templates'=>[ListConfiguration::class,null], 'template'=>[ViewConfiguration::class,$off->id], 'edit'=>[EditConfiguration::class,$profile->id]] as $name=>[$class,$record]) {
    $page = Livewire::actingAs($manager)->test($class, $record ? ['record'=>$record] : []);
    $path = $argv[1].'/'.$name.'.html';
    writePanelPage($path, $page, implode('', $page->effects['partials'] ?? []));
    if ($name === 'edit') {
        // skin-render has no Livewire runtime. Show the native editor's prefilled form state.
        $state = $page->get('data');
        $html = preg_replace_callback('~<input\b[^>]*wire:model[^=\s]*="data\.name"[^>]*>~s',
            fn($m) => preg_replace('~\s*/?>$~',' value="'.e($state['name']).'">',$m[0]), file_get_contents($path));
        $html = preg_replace_callback('~(<textarea\b[^>]*wire:model[^=\s]*="data\.([\w]+)"[^>]*>).*?(</textarea>)~s',
            fn($m) => $m[1].e($state[$m[2]] ?? '').$m[3], $html);
        writeRenderArtifact($path,$html);
    }
}
$nativeSuccessful = true;
