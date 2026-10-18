<?php
// Native Filament actions and financial boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/renewal-execution.php';
require __DIR__.'/staff-panel.php';
use App\Models\{Credit,InvoiceTransaction};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork,Refunds,RenewalReplacements,StaffOperationReview,StaffOperations};
staffPanel(); ObservationSchema::install();
app()->instance(Clock::class,$clock); app()->instance(Refunds::class,new Refunds($clock));
app()->instance(Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class,new Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion($renewBindings,$clock));
app()->instance(Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::class,$reconcile);
app()->instance(ObservationWork::class,new ObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock)),$clock,new AccountReadBudget($clock)));
$all=[Staff::VIEW,'admin.domains.manage','admin.domains.fund','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$all[]=StaffOperationReview::permission($action);}
$staff=staffUser($all); Auth::setUser($staff); $service=new StaffOperations($clock);
$ticket=fn($op,$action)=>StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock);
$run=fn($op,$action,$key=null)=>$service->run($staff,$op->domain_id,$op->id,$action,$key??$ticket($op,$action),'Reviewed current evidence');
$held=static function($name)use($new){$op=$new($name);$op->update(['state'=>'held','safe_code'=>'staff_review_required']);return $op->fresh();};
$credit=fn()=>Credit::where('user_id',$user->id)->sum('amount');
