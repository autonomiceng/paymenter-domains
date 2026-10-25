<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/native-app.php';
// The general fixture suppresses native audit noise. Enable the upstream default before booting
// cancellation models, and include console events only for this synthetic CLI qualification.
config(['audit.enabled'=>true,'audit.console'=>true]);
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Service,ServiceCancellation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCancellation,NativeIdentity,NativeIntentSchema};
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
// Negative discovery stays retryable until the extension table is installed.
same([],NativeCancellation::withIntents(collect(),[999999],true)->all(),'Absent intent storage is harmless');
NativeIntentSchema::install();
DB::enableQueryLog();DB::flushQueryLog();
NativeCancellation::withIntents(collect(),[999999],true);
NativeCancellation::withIntents(collect(),[999999],false);
$schema=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'sqlite_master'));
DB::disableQueryLog();
same(1,count($schema),'Successful intent discovery is reused by advisory and locked consumers');
NativeCancellation::boot();
$plain=new Service;$plain->exists=true;$plain->setRawAttributes(['id'=>999999,'status'=>'active','label'=>'Before'],true);$plain->label='After';
DB::enableQueryLog();DB::flushQueryLog();
Service::getEventDispatcher()->dispatch('eloquent.updating: '.Service::class,$plain);
$queries=DB::getQueryLog();DB::disableQueryLog();
same([], $queries,'Safe proposed status performs no native cancellation precheck reads');
NativeIdentity::boot();
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
// Eloquent accepts the case alias and SQLite applies it after the ordinary status key.
DB::table('services')->where('id',$op->service_id)->update(['status'=>'pending']);
$aliasControl=$op->domain->service->fresh();$aliasControl->forceFill(['status'=>'active','STATUS'=>'suspended'])->save();
same('suspended',$aliasControl->fresh()->status,'Eloquent carries a status alias through to the native SQL update');
foreach(['STATUS','services.status']as$alias){foreach(['cancelled','suspended']as$status){
    $guarded=$new(placeholderComFixtureName('reactivation-alias-'.strtolower(str_replace('.','-',$alias)).'-'.$status.'-placeholder.com'));
    $service=$guarded->domain->service->fresh();same('pending',$service->status,'Paid acquisition still awaits its native activation');
    $refused=false;try{$service->forceFill(['status'=>'active',$alias=>$status])->save();}
    catch(\Illuminate\Validation\ValidationException $e){$refused=true;same(NativeCancellation::MESSAGE,$e->errors()['status'][0],'Unsafe status alias reaches the cancellation guard');}
    expect($refused,'Reactivation cannot conceal a '.$status.' status alias');
    same('pending',$service->fresh()->status,'Refused aliased reactivation retains native status');
    $service->fresh()->update(['status'=>'active']);same('active',$service->fresh()->status,'Plain native activation remains writable');
}}
echo "Native cancellation create/update/delete remain vanilla and use native audit history.\n";$nativeSuccessful=true;
