<?php
// Native static panel fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new \Paymenter\Extensions\Servers\Domains\Data\SystemClock);
staffPanel();[$held,$active,$long]=staffData();$op=$held->operations()->sole();
$op->update(['funding_basis'=>'paid_invoice','state'=>'uncertain','started_at'=>now()->subHours(73),'safe_code'=>'stale_claim']);
$permissions=[Staff::VIEW,'admin.domains.manage','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$permissions[]=StaffOperationReview::permission($action);}
$user=staffUser($permissions);Auth::setUser($user);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function($test,string $name)use($out,$css){
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page'=>$test->instance(),'body'=>$test->html().($test->effects['partials']['action-modals.0']??'')]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(!str_contains($html,'PRIVATE-'),'No synthetic secret in fixture');file_put_contents($out.'/'.$name.'.html',$html);
};
$render(Livewire::actingAs($user)->test(ListOperations::class)->assertSuccessful(),'queue');
$render(Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful(),'operation');
$render(Livewire::actingAs($user)->test(ViewDomain::class,['record'=>$long->id])->assertSuccessful(),'domain-long');
$completed=$active->operations()->sole();$completed->update(['attempt'=>['seal'=>'sealed','execution'=>['outcome'=>'pending']]]);
$render(Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$completed->id])->assertDontSee('Review actions')
    ->assertSee('Registrar completion confirmed. Original send evidence is retained for history.'),'operation-completed');
$rows=[];
foreach(StaffOperationReview::ACTIONS as $action=>$label){
    $copy=$op->replicate();$copy->safe_code='staff_review_required';$copy->operation_key='synthetic-modal-'.$action;$copy->state=match($action){'hold'=>'queued','credit','disposition','replacement'=>'rejected',default=>'held'};
    if($action==='replacement'){$copy->action='renew';}$copy->save();
    if($action==='resume_observation'){
        DB::table('domains_observation_work')->insert(['server_id'=>$held->server_id,'account_fingerprint'=>$held->account_fingerprint,
            'domain_id'=>$held->id,'generation'=>$held->authority_generation,'phase'=>'holding','due_at'=>0,'safe_code'=>'read_unavailable']);
    }
    $page=Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$copy->id])->mountAction($action)->assertActionMounted($action);
    $page->call('$refresh');$render($page,'modal-'.$action);$rows[$action]=['label'=>$label,'description'=>OperationActions::description($action)];
    if($action==='release'){
        $refusal=Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$copy->id])->callAction('release',['reason'=>'Synthetic binding is unavailable']);
        $render($refusal,'refusal-server');$html=file_get_contents($out.'/refusal-server.html');
        expect(str_contains($html,'Review refused')&&!str_contains($html,'A credit decision may be retained'),'Native nonfinancial refusal omits ledger warning');
        $page->setActionData(['reason'=>'no'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);$page->call('$refresh');$render($page,'refusal-reason');}
}
file_put_contents($out.'/strings.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Native operation queue, view, nine confirmation modals and refusal fixture rendered.\n";$nativeSuccessful=true;
