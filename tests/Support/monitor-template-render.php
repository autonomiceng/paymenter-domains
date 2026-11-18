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
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
$off = DB::transaction(fn () => WatchProfile::create(['name'=>'Previous hosting template','nameservers'=>['ns1.old.test','ns2.old.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic former template']));
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$off->id,'profile_revision'=>1,'expected_dns'=>null]);
$at = app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()-1800;
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$at,'backoff_until'=>null]);
$ticket = $registry->begin($id,$uid,$at);
expect($ticket && $registry->finish($ticket,['result'=>'unknown','reason'=>'endpoint_failed','safe_code'=>'expiry_unknown'],str_repeat('c',64),$at+1),'Synthetic failed registry read');
config(['domains.site_dns_default.nameservers'=>$profile->nameservers]);
siteDnsFixture($profile->id);
$manager->role->update(['permissions'=>[...$manager->role->permissions,\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::POLICY,
    \Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::MANAGE]]);
\Paymenter\Extensions\Servers\Domains\Admin\Support\DialogFooter::boot();
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
foreach (['watch'=>[ViewWatch::class,$id], 'assignment'=>[ViewWatch::class,$id], 'templates'=>[ListConfiguration::class,null], 'template'=>[ViewConfiguration::class,$off->id],
    'edit'=>[EditConfiguration::class,$off->id]] as $name=>[$class,$record]) {
    $page = Livewire::actingAs($manager)->test($class, $record ? ['record'=>$record] : []);
    if ($name === 'assignment') {
        $page->mountAction(TestAction::make('profile')->schemaComponent('sheet','content'));
    }
    $path = $argv[1].'/'.$name.'.html';
    writePanelPage($path, $page, implode('', $page->effects['partials'] ?? []));
    if ($name === 'edit') {
        // skin-render has no Livewire runtime. Show the native editor's prefilled form state.
        $state = $page->get('data');
        $html = preg_replace_callback('~<input\b[^>]*wire:model="data\.name"[^>]*>~s',
            fn($m) => preg_replace('~\s*/?>$~',' value="'.e($state['name']).'">',$m[0]), file_get_contents($path));
        $html = preg_replace_callback('~(<textarea\b[^>]*wire:model="data\.([\w]+)"[^>]*>).*?(</textarea>)~s',
            fn($m) => $m[1].e($state[$m[2]] ?? '').$m[3], $html);
        writeRenderArtifact($path,$html);
    } elseif ($name === 'assignment') {
        $html = file_get_contents($path);
        // Freeze the mounted action and its native select in the open state Alpine would display.
        $html = str_replace('class="fi-modal fi-absolute-positioning-context"', 'class="fi-modal fi-modal-open fi-absolute-positioning-context"', $html);
        $html = preg_replace('/x-cloak\s+x-show="isOpen"/','',$html);
        $selected = e($page->get('mountedActions.0.data.assignment'));
        $html = str_replace('value="'.$selected.'"', 'selected value="'.$selected.'"', $html);
        writeRenderArtifact($path,$html);
    }
}
$nativeSuccessful = true;
