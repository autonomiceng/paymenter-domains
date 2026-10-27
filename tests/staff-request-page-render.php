<?php
// Static captures of Registrar requests and the request page for the main request states; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Blade, DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations, ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
$out=$argv[1]??'';
expect(is_dir($out)&&is_writable($out),'Output directory argument required');
staffPanel(); [$held,$active,$long]=staffData();
// Notice links lead to Registrars, so its route is registered too.
$setupPanel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(fn()=>Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::registerRoutes($setupPanel));
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
[$first,$done]=Operation::orderBy('id')->get()->all();
DB::table('domains_operations')->update(['created_at'=>'2026-10-20 15:05:00']);
// Request #1 is not sent for a real cause; the other states are copies of it.
DB::table('domains_operations')->where('id',$first->id)->update(['safe_code'=>'configuration_unavailable','attempt'=>null]);
$copy=static function(string $key,array $set,array $model=[])use($first): Operation {
    $op=$first->fresh()->replicate(); $op->operation_key='render-'.$key; $op->save();
    DB::table('domains_operations')->where('id',$op->id)->update($set);
    if ($model) { Operation::find($op->id)->forceFill($model)->saveQuietly(); }
    return Operation::find($op->id);
};
$pending=$copy('pending',['state'=>'pending','safe_code'=>null,'started_at'=>'2026-10-20 15:06:00']);
$hold=['key'=>'r1','action'=>'hold','phase'=>'completed','actor_id'=>1,'actor_name'=>'Ada Admin','reason'=>'Put on hold','at'=>'2026-10-21T09:00:00+00:00'];
$review=$copy('review',['state'=>'held','safe_code'=>'staff_review_required'],['policy'=>['staff_actions'=>[$hold]]]);
$unknown=$copy('unconfirmed',['state'=>'uncertain','safe_code'=>null,'funding_basis'=>'paid_invoice','started_at'=>now()->subHours(73)]);
$refunded=$copy('refunded',['state'=>'rejected','safe_code'=>'terminal_unsent','funding_basis'=>'paid_invoice'],
    ['policy'=>['staff_actions'=>[$hold,['key'=>'r2','action'=>'refund','phase'=>'completed','actor_id'=>1,'actor_name'=>'Ada Admin',
        'reason'=>'Customer cancelled before sending','at'=>'2026-10-21T10:00:00+00:00']]],'funding'=>['refund'=>['to'=>'account_credit']]]);
$panel=Filament::getCurrentPanel();
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$loadingStyles=\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$user=staffUser([Staff::VIEW,Staff::COSTS,Staff::METADATA,'admin.domains.manage','admin.invoices.update',
    ...array_map(StaffOperationReview::permission(...),array_keys(StaffOperationReview::ACTIONS))]); Auth::setUser($user);
foreach (['list'=>[ListOperations::class,[]],'not-sent'=>[ViewOperation::class,['record'=>$first->id]],'pending'=>[ViewOperation::class,['record'=>$pending->id]],
    'under-review'=>[ViewOperation::class,['record'=>$review->id]],'paid-uncertainty'=>[ViewOperation::class,['record'=>$unknown->id]],
    'completed'=>[ViewOperation::class,['record'=>$done->id]],'cancelled-refunded'=>[ViewOperation::class,['record'=>$refunded->id]]] as $name=>[$class,$params]) {
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
    expect(!preg_match('/\bunavailable\b/i',strip_tags($html)),'No "unavailable" text on '.$name);
    writeRenderArtifact($out.'/'.$name.'.html',$html);
}
echo "Registrar requests list and request page captures rendered.\n";
$nativeSuccessful=true;
