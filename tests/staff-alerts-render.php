<?php
// Static captures of the staff alert pages: a staff-held transfer, an unconfirmed paid registration that holds its account, and the Domains list; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Blade, DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
$out=$argv[1]??'';
expect(is_dir($out)&&is_writable($out),'Output directory argument required');
staffPanel(); [$held,$active,$pending]=staffData(ordinaryCompleted:true);
$setupPanel=Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(fn()=>Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::registerRoutes($setupPanel));
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
DB::table('domains_domains')->update(['observation'=>null]);
DB::table('domains_domains')->where('id',$active->id)->update(['observation'=>json_encode($active->observation)]);
// A transfer a staff member held before it was sent (J6 domain 43, request #71).
$transfer=Operation::where('domain_id',$held->id)->sole();
DB::table('domains_operations')->where('id',$transfer->id)->update(['state'=>'held','safe_code'=>'staff_review_required','attempt'=>null,'created_at'=>'2026-10-31 20:02:00']);
Operation::find($transfer->id)->forceFill(['policy'=>['staff_actions'=>[['key'=>'h1','action'=>'hold','phase'=>'completed','actor_id'=>1,'actor_name'=>'Alex Admin',
    'reason'=>'Put on hold','at'=>'2026-11-03T22:04:00+00:00']]]])->saveQuietly();
// A paid registration sent and never confirmed (J6 domain 50, request #111): it holds paid changes on its whole account.
DB::table('domains_domains')->where('id',$pending->id)->update(['status'=>'registration_pending','provider_expires_at'=>null]);
$lost=Operation::where('domain_id',$pending->id)->sole();
DB::table('domains_operations')->where('id',$lost->id)->update(['action'=>'register','state'=>'uncertain','safe_code'=>null,'funding_basis'=>'paid_invoice',
    'started_at'=>now()->subHours(86),'created_at'=>now()->subHours(86)]);
Operation::find($lost->id)->forceFill(['policy'=>['status_check'=>['outcome'=>'awaiting_answer','registrar'=>'NameSilo','at'=>time()-3600,
    'answer_after'=>time()-62*3600]]])->saveQuietly();
$panel=Filament::getCurrentPanel();
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$loadingStyles=\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles();
$staffStyles=view('domains::partials.staff-styles')->render();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$user=staffUser([Staff::VIEW,Staff::COSTS,Staff::METADATA,'admin.domains.manage','admin.invoices.update',
    ...array_map(StaffOperationReview::permission(...),array_keys(StaffOperationReview::ACTIONS))]); Auth::setUser($user);
foreach (['alerts-list'=>[ListDomains::class,[]],'held-transfer-domain'=>[ViewDomain::class,['record'=>$held->id]],
    'held-transfer-request'=>[ViewOperation::class,['record'=>$transfer->id]],'unconfirmed-domain'=>[ViewDomain::class,['record'=>$pending->id]],
    'unconfirmed-request'=>[ViewOperation::class,['record'=>$lost->id]]] as $name=>[$class,$params]) {
    $test=Livewire::actingAs($user)->test($class,$params)->assertSuccessful();
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    // Static visual capture only: component interactions are covered by the native test suite.
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);
    $html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);
    $html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.$loadingStyles.$staffStyles.'</head>',$html);
    expect(!str_contains($html,'PRIVATE-'),'Synthetic secret must not enter the rendered fixture');
    writeRenderArtifact($out.'/'.$name.'.html',$html);
}
echo "Staff alert page captures rendered.\n";
$nativeSuccessful=true;
