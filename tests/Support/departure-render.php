<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real-page capture with synthetic records.
declare(strict_types=1);
require __DIR__.'/observation-records-ready.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains,ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,DepartureChecks};
$out=$argv[1]??'';expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
$clock->instant=$clock->now()->modify('+61 seconds');$observation->fault='absent';$observer->run($d->id,readRecords:true);
DB::transaction(function()use($d,$clock):void{$fresh=$d->fresh();$p=$fresh->observation;$p['departure_check']['registry']=['registrar'=>'Example Registrar','status'=>['ok'],'result'=>'facts','at'=>$clock->now()->getTimestamp()];$fresh->update(['observation'=>$p]);});
app()->instance(OwnedDomains::class,$owners);
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
$domain=$owners->present($d->fresh());
session()->forget(['domains_failed_form','_old_input']);
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS],str_repeat('a',64)));
// As OwnedDomainController::page: the read record families merge into the page before it renders.
$families=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now());$domain=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::owner($domain,$families);
$customer=view('domains::show',['domain'=>$domain,'recordFamilies'=>$families])->render();
$colors=':root{--color-primary:190 100% 50%;--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}body{margin:0;padding:24px;background:hsl(var(--color-background));font:15px/1.5 system-ui}.container{max-width:1180px;margin:auto}';
writeRenderArtifact($out.'/customer.html',str_replace(['<html>','</head>'],['<html lang="en" class="dark">','<style>'.$colors.'</style></head>'],$customer));
staffPanel();$actor=staffUser([Staff::VIEW,'admin.domains.manage','admin.domains.holdings.stop']);Auth::setUser($actor);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
$page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
writePanelPage($out.'/staff.html',$page);
$list=Livewire\Livewire::actingAs($actor)->test(ListDomains::class)->filterTable('alerts',true)->assertCanSeeTableRecords([$d]);
writePanelPage($out.'/attention-fresh.html',$list);
$clock->instant=$clock->now()->modify('+2 days');
$list=Livewire\Livewire::actingAs($actor)->test(ListDomains::class)->filterTable('alerts',true)->assertCanSeeTableRecords([$d]);
writePanelPage($out.'/attention.html',$list);
writeRenderArtifact($out.'/attention.txt',\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::alertCause('departure_review',$d->fresh()));
$nativeSuccessful=true;echo "Customer and staff suspected pages rendered offline.\n";
