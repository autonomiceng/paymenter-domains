<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        // Only the upgrade cohort is pinned. Existing rows, including changed identities and custom days, stay untouched.
        $last=DB::table('domains_accounts')->max('id');
        DB::table('domains_accounts')->where('id','<=',$last??0)->orderBy('id')->chunkById(100,static function($accounts):void {
            foreach($accounts as $account){
                DB::table('domains_reminder_schedules')->insertOrIgnore(['server_id'=>$account->server_id,'account_fingerprint'=>$account->account_fingerprint,
                    'enabled'=>false,'before_days'=>'[1,7,30,60]','after_days'=>'[3]','audit'=>encrypt([])]);
            }
        });
    }
    public function down(): void { /* Retain reminder choices and account enrollment. */ }
};
