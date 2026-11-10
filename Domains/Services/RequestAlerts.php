<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,Staff,StaffAlert};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
/** Staff bell notice when a domain change request starts to need staff: Failed, or Under review once a registrar read has
 * not confirmed it, or held with no automatic retry left. The receipt key is the request and its status word, so later
 * reads of the same request never notify again. */
final class RequestAlerts
{
    private const WORDS=['under_review'=>['Registrar request needs a check',' is under review.'],'failed'=>['Registrar request failed',' failed.']];
    public static function changed(Operation $op): void
    {
        try{
            if(!$op->wasChanged(['state','attempt'])||!isset($op->policy['management'])){return;}
            $status=RequestStatus::key(RequestStatus::readsAs($op->state,$op->safe_code));
            if(!isset(self::WORDS[$status])){return;}
            // Automatic checks and retries are still working on these, so staff have nothing to do yet.
            if($status==='under_review'&&($op->state==='uncertain'?ManagementReadRecovery::latest($op)===[]:ManagementEvidence::recoverable($op))){return;}
            [$title,$end]=self::WORDS[$status];$id=(int)$op->id;
            $body=Labels::action($op->action).' for '.($op->domain()->value('domain')??'a domain').$end;
            DB::afterCommit(static fn()=>StaffAlert::send($title,$body,'filament.admin.resources.registrar-requests.view',Staff::VIEW,'request:'.$id.':'.$status,['record'=>$id]));
        }catch(\Throwable){Log::warning('Registrar request notice could not be prepared.',['operation_id'=>$op->id]);}
    }
}
