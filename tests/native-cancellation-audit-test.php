<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/native-app.php';
// The general fixture suppresses native audit noise. Enable the upstream default before booting
// cancellation models, and include console events only for this synthetic CLI qualification.
config(['audit.enabled'=>true,'audit.console'=>true]);
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Services\{NativeCancellation,NativeIdentity,NativeIntentSchema};
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIntentSchema::install();NativeIdentity::boot();NativeCancellation::boot();
$actor=staffUser(['admin.domains.view']);Auth::login($actor);
[$op]=$prepare(placeholderComFixtureName('native-cancel-audit-placeholder.com'));same('succeeded',$reconcile->run($op->id)->state,'Completed managed holding');
Auth::login($actor);
$row=ServiceCancellation::create(['service_id'=>$op->service_id,'type'=>'end_of_period','reason'=>'Native stop future renewal']);
$row->update(['reason'=>'Native audited reason edit']);
$row->delete();
$audits=$row->audits()->orderBy('id')->get();
same(['created','updated','deleted'],$audits->pluck('event')->all(),'Vanilla native cancellation model audits every ordinary mutation');
foreach($audits as$audit){same($actor->id,(int)$audit->user_id,'Native audit actor retained');}
same('Native audited reason edit',$audits->firstWhere('event','updated')->new_values['reason'],'Native audit records updated reason');
same($op->service_id,(int)$audits->firstWhere('event','deleted')->old_values['service_id'],'Native deletion retains service identity');
same('active',$op->domain->service->fresh()->status,'Native end-period row deletion never mutates registrar/native status');
echo "Native cancellation create/update/delete remain vanilla and use native audit history.\n";$nativeSuccessful=true;
