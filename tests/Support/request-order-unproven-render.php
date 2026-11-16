<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline staff capture of a renewal with an order number whose checks stopped, synthetic records.
declare(strict_types=1);
// Look evidence comes only from tools/bin/skin-render, which sets the host skin and its boot marker. Refuse before any setup.
if((string)getenv('DOMAINS_PANEL_SETUP')===''||(string)getenv('SKIN_RENDER_MARKER')===''){throw new RuntimeException('Run this fixture through tools/bin/skin-render.');}
$out=$argv[1]??'';
require __DIR__.'/renewal-execution.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,OperationReconciliation,RegistrarAnswers,StaffOperationReview,StaffSettingsReview};
$nativeSuccessful=false;expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
staffPanel();
// Enom accepted the renewal with an order number; order detail never lists it, and the domain's expiry is unchanged.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-unproven-placeholder.com'));[$engine,$writer,$completion]=$compose($b);
$engine->run($op->id,$writer);$state->order='7777';$state->after='2027-10-18';$clock->instant=$clock->now()->modify('+25 hours');Cache::flush();
for($i=0;$i<OperationReconciliation::POLL_LIMIT;$i++){$completion->run($op->id);$clock->instant=$clock->now()->modify('+10 minutes');}
expect(RegistrarAnswers::ready($op->fresh(),$clock->now()),'Checks stopped and the answer can be recorded');
$staff=staffUser([Staff::VIEW,'admin.domains.manage','admin.invoices.update',Staff::POLICY,StaffSettingsReview::permission('enom_answer'),
    ...array_map(fn($a)=>StaffOperationReview::permission($a),['recheck','resume_reads','mark_failed'])]);Auth::setUser($staff);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));
writePanelPage($out.'/request-order-stopped.html',Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]));
$nativeSuccessful=true;echo "Stopped renewal request page rendered offline.\n";
