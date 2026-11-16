<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFindings;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites;
$nativeSuccessful=false;
$f=discoveryFixture();extract($f);$sweep();
$w=WatchName::sole();
// Hosting ended without a staff pause: monitoring returns on its own, and the stopped page says so.
DB::table('services')->where('id',$service->id)->update(['status'=>'cancelled']);$sweep();
same('retired',$w->fresh()->state,'The last hosting cancellation stops monitoring');
expect(WatchFindings::restart($w->fresh())===WatchFindings::RESTART&&WatchFindings::restart($w->fresh(),true)===WatchFindings::RESTART_SHORT,'A stopped domain with no staff pause takes the plain restart branch');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);$sweep();
same('active',$w->fresh()->state,'Returning hosting starts checks');
// Staff paused it, then hosting ended: it returns paused, so the stopped page takes the Resume branch.
$staff=staffUser(['admin.domains.view','admin.domains.watch.view','admin.domains.watch.manage']);$w->refresh();$writes=new StaffWatchWrites;
$writes->run($staff,'watch',$w->id,'pause',StaffWatchWrites::ticket($staff->id,'watch',$w->id,$w->revision,'pause'),'Synthetic staff pause');
DB::table('services')->where('id',$service->id)->update(['status'=>'cancelled']);$sweep();
same('retired',$w->fresh()->state,'Hosting cancellation stops the paused domain');
expect(WatchFindings::restart($w->fresh())===WatchFindings::RESTART_PAUSED&&WatchFindings::restart($w->fresh(),true)===WatchFindings::RESTART_PAUSED_SHORT,'A stopped domain that staff paused takes the Resume branch');
expect(str_ends_with(WatchFindings::stoppedReason($w->fresh()),WatchFindings::RESTART_PAUSED),'The stopped page reason uses the Resume branch');
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);$sweep();
same('paused',$w->fresh()->state,'It returns paused, as the Resume branch says');
echo "A stopped monitored domain says how it starts again, including the Resume step after a staff pause.\n";
$nativeSuccessful=true;
