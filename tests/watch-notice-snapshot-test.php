<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{DB,Schema};
use App\Models\{User,NotificationTemplate,NotificationPreference};
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticeDelivery;
$f=noticeFixture();extract($f);$template=NotificationTemplate::where('key','domains_watch_expiring')->sole();
$current=new ReflectionMethod(WatchNoticeDelivery::class,'current');$socket=getenv('WATCH_NOTICE_MYSQL_SOCKET');
if ($socket) {
    $rows=['users'=>['id'=>$uid,'email'=>$owner->email,'role_id'=>$owner->role_id],'roles'=>$owner->role->getRawOriginal(),'domains_watch_profiles'=>$profile->getRawOriginal(),'notification_templates'=>$template->getRawOriginal()];
    $cfg=['driver'=>'mysql','unix_socket'=>$socket,'database'=>getenv('WATCH_NOTICE_MYSQL_DATABASE'),'username'=>'root','password'=>getenv('WATCH_NOTICE_MYSQL_PASSWORD'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true];
    config(['database.connections.notice_mvcc'=>$cfg,'database.connections.notice_peer'=>$cfg,'database.default'=>'notice_mvcc']);
    foreach($rows as $table=>$row) {
        Schema::create($table,static function($t)use($row){foreach($row as $k=>$v){if($k==='id'){$t->unsignedBigInteger($k)->primary();}elseif(is_int($v)||is_bool($v)){$t->integer($k)->nullable();}else{$t->text($k)->nullable();}}});DB::table($table)->insert($row);
    }
    Schema::create('notification_preferences',static function($t){$t->id();$t->unsignedBigInteger('user_id')->index();$t->unsignedBigInteger('notification_template_id')->index();$t->boolean('mail_enabled');$t->boolean('in_app_enabled');$t->timestamps();});
}
$template=NotificationTemplate::findOrFail($template->id);$prepared=['template'=>$template,'stamp'=>hash('sha256',serialize($template->getRawOriginal())),'email'=>$owner->email];
DB::transaction(fn()=>expect($current->invoke(null,$prepared,$uid,'mail'),'No native preference defaults on'));
if ($socket) {
    foreach(['missing','existing','template'] as $case) {
        DB::table('notification_preferences')->delete();$template->update(['subject'=>'Synthetic unchanged']);$template->refresh();$prepared['stamp']=hash('sha256',serialize($template->getRawOriginal()));
        if($case==='existing'){NotificationPreference::create(['user_id'=>$uid,'notification_template_id'=>$template->id,'mail_enabled'=>true,'in_app_enabled'=>true]);}
        DB::beginTransaction();DB::table('notification_templates')->where('id',$template->id)->first(); // Establish REPEATABLE READ snapshot before concurrent commit.
        if($case==='template'){DB::connection('notice_peer')->table('notification_templates')->where('id',$template->id)->update(['subject'=>'Concurrent staff edit']);}
        elseif($case==='existing'){DB::connection('notice_peer')->table('notification_preferences')->where('user_id',$uid)->update(['mail_enabled'=>false]);}
        else{DB::connection('notice_peer')->table('notification_preferences')->insert(['user_id'=>$uid,'notification_template_id'=>$template->id,'mail_enabled'=>false,'in_app_enabled'=>true]);}
        $snapshot=NotificationPreference::where('user_id',$uid)->first();
        if($case==='missing'){same(null,$snapshot,'Snapshot cannot see concurrent preference insert');}
        if($case==='existing'){expect($snapshot->mail_enabled,'Snapshot retains old enabled preference');}
        if($case==='template'){same('Synthetic unchanged',NotificationTemplate::find($template->id)->subject,'Snapshot retains old template');}
        expect(!$current->invoke(null,$prepared,$uid,'mail'),'Locking final check sees committed '.$case.' change');DB::rollBack();
    }
    DB::table('roles')->where('id',$owner->role_id)->update(['permissions'=>json_encode(['admin.domains.view','admin.domains.watch.manage'])]);
    DB::beginTransaction();expect(\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeReview::allowed($uid),'Snapshot initially has staff permission');
    DB::connection('notice_peer')->table('roles')->where('id',$owner->role_id)->update(['permissions'=>'[]']);
    expect(!\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeReview::allowed($uid,true),'Locked role sees concurrent revocation');DB::rollBack();
    DB::beginTransaction();$old=DB::table('domains_watch_profiles')->where('id',$profile->id)->first();
    DB::connection('notice_peer')->table('domains_watch_profiles')->where('id',$profile->id)->update(['revision'=>$old->revision+1]);
    same((int)$old->revision,(int)DB::table('domains_watch_profiles')->where('id',$profile->id)->value('revision'),'Snapshot retains old profile revision');
    $locked=DB::table('domains_watch_profiles')->where('id',$profile->id)->lockForUpdate()->first();
    $fact=(object)['id'=>1,'source_digest'=>hash('sha256',json_encode($old)),'result'=>'complete','partial'=>false,'dns'=>'external','started_at'=>1,'valid_until'=>100];
    same(null,\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeTarget::dnsFact($fact,hash('sha256',json_encode($locked)),2,'profile:'.$profile->id,true),'Locked revised profile stamp refuses prior confirmation');DB::rollBack();
    echo "Real MariaDB role revocation and profile revision snapshots refused.\n";
    echo "Real MariaDB snapshot interleavings: missing preference, existing opt-out and template edit refused.\n";
} else {
    NotificationPreference::create(['user_id'=>$uid,'notification_template_id'=>$template->id,'mail_enabled'=>false,'in_app_enabled'=>true]);
    DB::transaction(fn()=>expect(!$current->invoke(null,$prepared,$uid,'mail'),'Final native preference refuses opt-out'));
    echo "Native final preference check passed; optional MariaDB snapshot rehearsal requires an isolated socket.\n";
}
$nativeSuccessful=true;
