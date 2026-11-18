<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
if (!getenv('DOMAINS_PANEL_SETUP') || !getenv('SKIN_RENDER_MARKER')) { throw new RuntimeException('Run through skin-render.'); }
require __DIR__.'/staff-monitoring.php';
require_once __DIR__.'/site-dns-default.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{EditConfiguration, ListConfiguration, ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
$off = DB::transaction(fn () => WatchProfile::create(['name'=>'Previous hosting template','nameservers'=>['ns1.old.test','ns2.old.test'],
    'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic former template']));
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$off->id,'profile_revision'=>1,'expected_dns'=>null]);
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['safe_code'=>'expiry_unknown']);
siteDnsFixture($profile->id);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
foreach (['watch'=>[ViewWatch::class,$id], 'templates'=>[ListConfiguration::class,null], 'template'=>[ViewConfiguration::class,$off->id],
    'edit'=>[EditConfiguration::class,$off->id]] as $name=>[$class,$record]) {
    $page = Livewire::actingAs($manager)->test($class, $record ? ['record'=>$record] : []);
    writePanelPage($argv[1].'/'.$name.'.html', $page);
}
$nativeSuccessful = true;
