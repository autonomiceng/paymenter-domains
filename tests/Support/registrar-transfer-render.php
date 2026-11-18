<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline render of registrar account and overdue transfer panels.
declare(strict_types=1);
if (!is_file((string) getenv('DOMAINS_PANEL_SETUP')) || (string) getenv('SKIN_RENDER_MARKER') === '') {
    fwrite(STDERR, "This capture runs only through skin-render.\n");
    exit(1);
}
require __DIR__.'/transfer-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
require_once __DIR__.'/staff-tlds.php';
require_once __DIR__.'/panel-render.php';
require_once __DIR__.'/render-artifacts.php';

use Illuminate\Support\Facades\{Auth, Route};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount, RegistrarHistory};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\TransferFollowup;

tldPanel();
$panel=Filament\Facades\Filament::getCurrentPanel();
Route::name('filament.admin.')->prefix($panel->getPath())->middleware($panel->getMiddleware())
    ->group(fn()=>RegistrarHistory::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
app()->instance(Clock::class,$clock);
$staff=staffUser([...array_keys(Staff::permissions()),'admin.users.view','admin.users.update','admin.orders.update','admin.invoices.update']);
Auth::setUser($staff);
$out=$argv[1]??'';
expect(is_dir($out),'Render output exists');
config(['domains.time_zone'=>'America/Los_Angeles']);
panelTheme(getenv('PAYMENTER_TEST_FIXTURE'));

$op=$newTransfer(placeholderComFixtureName('staff-transfer-look-placeholder.com'));
$sent=$clock->now()->modify('-8 days');
$last=$clock->now()->modify('-1 day')->getTimestamp();
$read=['transfer_status'=>'pending','reason'=>'transfer_deadline_passed','staff_alert'=>true,'read_at'=>$last,'final_check'=>'done'];
$op->forceFill(['state'=>'uncertain','safe_code'=>'reconciliation_required','started_at'=>$sent,'created_at'=>$sent->modify('-5 minutes'),
    'attempt'=>['seal'=>'sealed','dispatch_attempted_at'=>$sent->format(DATE_ATOM),'execution'=>['send_state'=>'sent','disposition'=>'pending']],
    'policy'=>['reconciliation'=>$read]])->saveQuietly();
$op->forceFill(['policy'=>TransferFollowup::record($op, $op->policy, $last, 'pending')])->saveQuietly();

writePanelPage($out.'/registrar-account.html',Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$op->server_id])->assertSuccessful());
writePanelPage($out.'/transfer-checks-stopped.html',Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful());
$read=$op->policy['reconciliation'];
$read['final_check']='pending';
$read['next_at']=$clock->now()->getTimestamp()+3600;
$op->forceFill(['policy'=>['reconciliation'=>$read]])->saveQuietly();
writePanelPage($out.'/transfer-checks-scheduled.html',Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful());
same([],$state->calls,'No provider sends for panel rendering');
same(0,$state->reads,'No provider reads for panel rendering');
$nativeSuccessful=true;
