<?php
// Static captures of the staff domain page and Domains list for the main page states; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Blade, DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, HoldingProvenanceSchema, PslReviewSchema};
$out=$argv[1]??'';
expect(is_dir($out)&&is_writable($out),'Output directory argument required');
staffPanel(); [, $active]=staffData(ordinaryCompleted:true); HoldingProvenanceSchema::install(); PslReviewSchema::install();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$customer=App\Models\User::create(['first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.test','password'=>'synthetic-password']);
DB::table('domains_accounts')->update(['processing_enabled'=>true]);
DB::table('domains_domains')->update(['user_id'=>$customer->id,'last_synced_at'=>'2026-10-20 15:05:00']);
$copy=static function(string $name,array $set)use($active): Domain {
    static $service=600; $d=$active->fresh()->replicate(); $d->fill(['domain'=>$name,'sld'=>strstr($name,'.',true),'service_id'=>++$service,...$set]); $d->save(); return $d;
};
$failed=$copy('sync-failed.example.com',[]);
DB::table('domains_observation_work')->insert(['server_id'=>$failed->server_id,'account_fingerprint'=>$failed->account_fingerprint,
    'domain_id'=>$failed->id,'generation'=>$failed->authority_generation,'due_at'=>0,'safe_code'=>'read_unavailable','tries'=>5]);
// Resume sync acts through the domain's latest request.
$synced=$active->operations()->sole()->replicate(); $synced->fill(['domain_id'=>$failed->id,'service_id'=>$failed->service_id,'operation_key'=>'render-sync-failed']); $synced->save();
$noExpiry=$copy('no-expiry.example.com',['provider_expires_at'=>null]);
$withNs=$copy('nameservers.example.com',[]); $p=$withNs->observation;
$p['sections']['nameservers']=['operation_key'=>'render-ns','at'=>'2026-10-20T15:05:00Z','facts'=>['nameservers'=>['ns1.example.net','ns2.example.net','ns3.example.net']]]; $withNs->update(['observation'=>$p]);
$review=$copy('import-review.example.com',[]); DB::table('domains_domains')->where('id',$review->id)->update(['provenance'=>'adoption_pending']);
$order=$copy('new-order.example.com',['status'=>'registration_pending','authority_generation'=>1]);
$op=$active->operations()->sole()->replicate(); $op->fill(['domain_id'=>$order->id,'service_id'=>$order->service_id,'operation_key'=>'render-order',
    'action'=>'register','state'=>'awaiting_payment','safe_code'=>null,'authority_generation'=>1]); $op->save();
require_once __DIR__.'/Support/accounts.php';
$demo=Domain::create([...fixtureAccount((int)App\Models\Server::max('id')+1,'enom','beco-demo-enom'),'domain'=>'beco-demo-held.com','sld'=>'beco-demo-held','tld'=>'com',
    'user_id'=>$customer->id,'service_id'=>699,'status'=>'active','provider_expires_at'=>today()->addDays(200)->format('Y-m-d'),
    'snapshot'=>['offline_demo'=>true],'observation'=>['facts'=>[]],'renewal_policy'=>[]]);
config(['domains.enom_identity.environment'=>'test']);
$panel=Filament::getCurrentPanel();
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$loadingStyles=\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$user=staffUser([Staff::VIEW,Staff::ADOPT_PREVIEW,'admin.domains.manage',AcquisitionRetirement::PERMISSION,'admin.domains.operations.resume_observation']); Auth::setUser($user);
foreach (['list'=>[ListDomains::class,[]],'active'=>[ViewDomain::class,['record'=>$active->id]],'nameservers'=>[ViewDomain::class,['record'=>$withNs->id]],'sync-failed'=>[ViewDomain::class,['record'=>$failed->id]],
    'demo-paused'=>[ViewDomain::class,['record'=>$demo->id]],'missing-expiry'=>[ViewDomain::class,['record'=>$noExpiry->id]],
    'under-review'=>[ViewDomain::class,['record'=>$review->id]],'pending-order'=>[ViewDomain::class,['record'=>$order->id]]] as $name=>[$class,$params]) {
    $test=Livewire::actingAs($user)->test($class,$params)->assertSuccessful();
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    // Static visual capture only: component interactions are covered by the native test suite.
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);
    $html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);
    $html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.$loadingStyles.'</head>',$html);
    expect(!str_contains($html,'PRIVATE-'),'Synthetic secret must not enter the rendered fixture');
    expect(!str_contains($html,"\u{2014}"),'No em dashes in rendered page');
    writeRenderArtifact($out.'/'.$name.'.html',$html);
}
echo "Staff domain page and list captures rendered.\n";
$nativeSuccessful=true;
