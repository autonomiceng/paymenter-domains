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
    expect(!str_contains($html,'PRIVATE-'),'No synthetic secret in fixture');writeRenderArtifact($out.'/'.$name.'.html',$html);
};
$render(Livewire::actingAs($user)->test(ListOperations::class)->assertSuccessful(),'queue');
$render(Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful(),'operation');
$render(Livewire::actingAs($user)->test(ViewDomain::class,['record'=>$long->id])->assertSuccessful(),'domain-long');
// The fixture's completed request already keeps sealed send history; attempt evidence is write-once.
$completed=$active->operations()->sole();
$render(Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$completed->id])->assertDontSee('More')
    ->assertSee('Completed'),'operation-completed');
$rows=[];
foreach(StaffOperationReview::ACTIONS as $action=>$label){
    $copy=$op->replicate();$copy->safe_code='staff_review_required';$copy->operation_key='synthetic-modal-'.$action;$copy->state=match($action){'hold'=>'queued','credit','disposition','replacement'=>'rejected',default=>'held'};
    if($action==='replacement'){$copy->action='renew';}$copy->save();
    // Only the money actions and Close request open a dialog; the rest run on click.
    $rows[$action]=['label'=>$label,'description'=>OperationActions::description($action)];
    if(in_array($action,['disposition','refund','credit','replacement'],true)){
        $page=Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$copy->id])->mountAction($action)->assertActionMounted($action);
        $page->call('$refresh');$render($page,'modal-'.$action);
    }
    if($action==='release'){
        // This display-only transfer has sealed attempt evidence and no native funding rows.
        Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$copy->id])->assertActionHidden('release');
    }
    if($action==='refund'){$page->setActionData(['reason'=>'no'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);$page->call('$refresh');$render($page,'refusal-reason');}
}
// Release needs the real checkout, paid invoice, contacts and unsent request, not a display-only copy.
$release=(static function(){
    require __DIR__.'/Support/operation-fixture.php';
    app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    $request=$new(placeholderComFixtureName('staff-render-release-placeholder.com'));
    $request->update(['state'=>'held','safe_code'=>'staff_review_required']);
    return $request->fresh();
})();
$attempt=$release->attempt;expect(($attempt??[])===[],'Release fixture has no attempt evidence');
$page=Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$release->id])
    ->assertActionVisible('release')->assertSee('Release hold');
$render($page,'operation-release');
// A still-releasable hold changed after opening the page. Its old review ticket must refuse.
$release->update(['safe_code'=>'balance']);
$page->callAction('release');
same('held',$release->fresh()->state,'Stale release leaves the request held');
$render($page,'refusal-server');$html=file_get_contents($out.'/refusal-server.html');
expect(str_contains($html,StaffOperationReview::CHANGED)&&str_contains($html,'update request')
    &&!str_contains($html,'A credit decision may be retained'),'Native nonfinancial refusal omits ledger warning');
Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$release->id])->assertActionVisible('release')
    ->callAction('release')->assertNotified('Hold released')->assertActionNotMounted('release');
same('queued',$release->fresh()->state,'Fresh release queues the unsent funded request without a modal');
same($attempt,$release->fresh()->attempt,'Release retains the unsent attempt');
writeRenderArtifact($out.'/strings.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Native operation queue, view, four confirmation modals and refusal fixture rendered.\n";$nativeSuccessful=true;
