<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffAlert};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Staff actions reach the native bell only after their audited change commits. */
final class StaffOperationNotices
{
    public static function completed(Operation $op,Domain $domain,array $action): void
    {
        $title=match($action['action']){
            'hold'=>'Request on hold','release'=>'Hold released','refund'=>'Request cancelled',default=>null,
        };
        if($title===null){return;}
        $id=(int)$op->id;
        $body=$domain->domain.': '.$title.' by '.($action['actor_name']??'Staff').'.';
        $key='staff-action:'.$id.':'.$action['key'];
        DB::afterCommit(static function()use($title,$body,$key,$id):void {
            try { StaffAlert::send($title,$body,'filament.admin.resources.registrar-requests.view',Staff::VIEW,$key,['record'=>$id]); }
            catch (\Throwable) { Log::warning('Staff action inbox delivery failed.',['operation_id'=>$id]); }
        });
    }
}
