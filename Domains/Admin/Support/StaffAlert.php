<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\{Notification,User};
use Illuminate\Support\Facades\{DB,Log,Route,Schema};
use Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema;
/** Native inbox delivery with optional retained episode/day receipts per recipient. */
final class StaffAlert
{
    public static function send(string $title,string $body,string $route,string $permission=Staff::VIEW,?string $deliveryKey=null): bool
    {
        // Check once per alert, never once per recipient. Older/absent native inboxes stay silent.
        if (!class_exists(Notification::class) || !Schema::hasTable('notifications')
            || !Schema::hasColumns('notifications',['user_id','title','body','url','created_at','updated_at'])) { return false; }
        if($deliveryKey!==null&&!Schema::hasTable(StaffAlertSchema::TABLE)){return false;}
        $url=Route::has($route)?route($route):null;
        $complete=true;
        foreach (User::whereHas('role')->with('role')->lazyById(100) as $staff) {
            if (Staff::allows($staff,$permission)) {
                // Same native model API as NotificationHelper::sendInAppNotification, without a template render.
                try{
                    $create=static function()use($staff,$title,$body,$url):Notification {
                        $notification=Notification::create(['user_id'=>$staff->id,'title'=>$title,'body'=>$body,'url'=>$url]);
                        if(!$notification->exists){throw new \RuntimeException('Native inbox save refused.');}
                        return $notification;
                    };
                    if($deliveryKey===null){$create();continue;}
                    DB::transaction(static function()use($deliveryKey,$staff,$create):void {
                        $key=hash('sha256',$deliveryKey.'|'.$staff->id);
                        DB::table(StaffAlertSchema::TABLE)->insertOrIgnore(['delivery_key'=>$key,'user_id'=>$staff->id]);
                        $receipt=DB::table(StaffAlertSchema::TABLE)->where('delivery_key',$key);
                        $row=(clone$receipt)->lockForUpdate()->firstOrFail();
                        if($row->notification_id!==null){return;}
                        // Persist native delivery and its receipt atomically, including a save veto.
                        $notification=$create();$receipt->update(['notification_id'=>$notification->id]);
                    });
                }catch(\Throwable){$complete=false;Log::warning('Staff inbox notification could not be saved.',['user_id'=>$staff->id]);}
            }
        }
        return $complete;
    }
}
