<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\{Notification,User};
use Illuminate\Support\Facades\{Route,Schema};
/** Native inbox persistence only; callers retain their own episode/day deduplication. */
final class StaffAlert
{
    public static function send(string $title,string $body,string $route,string $permission=Staff::VIEW): bool
    {
        // Check once per alert, never once per recipient. Older/absent native inboxes stay silent.
        if (!class_exists(Notification::class) || !Schema::hasTable('notifications')
            || !Schema::hasColumns('notifications',['user_id','title','body','url','created_at','updated_at'])) { return false; }
        $url=Route::has($route)?route($route):null;
        foreach (User::whereHas('role')->with('role')->lazyById(100) as $staff) {
            if (Staff::allows($staff,$permission)) {
                // Same native model API as NotificationHelper::sendInAppNotification, without a template render.
                Notification::create(['user_id'=>$staff->id,'title'=>$title,'body'=>$body,'url'=>$url]);
            }
        }
        return true;
    }
}
