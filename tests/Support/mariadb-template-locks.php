<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native connections, no provider calls.
// A holder takes the DNS template row lock as an assignment writer does; the staff turn off or delete waits on it.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
require_once __DIR__.'/FakeRegistrar.php';
use App\Models\{Role,User};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,SiteDnsDefault,StaffWatchWrites as Writes};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$root=(string)getenv('TPL_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('TPL_PHASE');$case=(string)getenv('TPL_CASE');$action=(string)getenv('TPL_ACTION');$prefix=(string)getenv('SCHEMA_PREFIX');
$name=$case.'-'.$action;
$signal=static fn(string $step)=>file_put_contents($root.'/'.$name.'-'.$step,'ready');
$wait=static function(string $step)use($root,$name):void {
    $end=microtime(true)+25;while(!is_file($root.'/'.$name.'-'.$step)){if(microtime(true)>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
};
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false)as$table){Schema::rename($table,$prefix.$table);}}
}
config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');
app()->instance(Clock::class,new FakeClock(new DateTimeImmutable('2026-11-09T12:00:00Z')));
if($phase==='seed'){
    (new Installation)->install();
    // The site default setting row exists, so the write's settings lock holds a real row.
    App\Models\Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>'']);
    $role=Role::create(['name'=>'Synthetic template staff','permissions'=>[Staff::VIEW,Writes::VIEW,Writes::MANAGE]]);
    $manager=User::create(['first_name'=>'Synthetic','last_name'=>'Staff','email'=>'template-staff@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
    $customer=User::create(['first_name'=>'Synthetic','last_name'=>'Customer','email'=>'template-customer@example.test','password'=>'synthetic-password']);
    $template=static fn(string $label)=>(int)DB::transaction(fn()=>WatchProfile::create(['name'=>$label,'enabled'=>true,
        'nameservers'=>['ns1.spare.test','ns2.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],
        'edited_by'=>$manager->id,'reason'=>'Synthetic template setup']))->id;
    $ids=['manager'=>$manager->id,'other'=>$template('Current watch template')];
    foreach(['late','timeout','deadlock']as$kind){foreach(['disable','delete']as$verb){
        $ids[$kind.'-'.$verb]=$template('Locked '.$kind.' '.$verb);
        $ids['watch-'.$kind.'-'.$verb]=(int)DB::transaction(fn()=>WatchName::create(['user_id'=>$customer->id,'canonical_name'=>$kind.'-'.$verb.'.example',
            'psl_revision'=>str_repeat('a',64),'state'=>'active','expected_dns'=>'platform','ns_profile_id'=>$ids['other'],
            'profile_revision'=>1,'notice_preferences'=>[]]))->id;
    }}
    file_put_contents($root.'/ids.json',json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Seeded DNS template lock fixtures.\n";$nativeSuccessful=true;return;
}
expect(in_array($case,['late','timeout','deadlock'],true)&&in_array($action,['disable','delete'],true),'Known lock case');
$ids=json_decode(file_get_contents($root.'/ids.json'),true,512,JSON_THROW_ON_ERROR);
$id=$ids[$name];$watch=$ids['watch-'.$name];$manager=User::findOrFail($ids['manager']);
$writes=new Writes;
if($phase==='hold'){
    // Only the waiting staff write blocked on this connection's template row lock counts.
    $profiles='`'.DB::getDatabaseName().'`.`'.DB::getTablePrefix().'domains_watch_profiles`';
    $observe=static function()use($root,$name,$profiles,$wait):void {
        $wait('started');$writer=(int)file_get_contents($root.'/'.$name.'-connection');
        $waits=static fn():int=>(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
            .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
            .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
            .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND l.lock_table=?',[$writer,$profiles])->n;
        // InnoDB refreshes these tables only after 0.1 s without a read; faster polls re-read the old state.
        $end=microtime(true)+20;
        while($waits()===0){if(microtime(true)>$end){throw new RuntimeException('The staff write did not wait on the template lock.');}usleep(200000);}
        file_put_contents($root.'/'.$name.'-lock-wait','proved');
    };
    if($case==='late'){
        // A real staff watch assignment, held open until the staff write waits on the template row it locked.
        $wait('snapshot');DB::beginTransaction();
        $writes->run($manager,'watch',$watch,'profile',Writes::ticket((int)$manager->id,'watch',$watch,(int)WatchName::findOrFail($watch)->revision,'profile'),
            'Synthetic late assignment',['profile'=>$id,'expected_dns'=>'platform']);
        $signal('assigned');$observe();DB::commit();$signal('committed');
    }else{
        DB::beginTransaction();
        if($case==='deadlock'){
            // Enough undo makes InnoDB choose the lighter staff write as the deadlock victim.
            $rows=array_map(static fn($i)=>['claim'=>hash('sha256','ballast-'.$name.'-'.$i),'subject_type'=>'ballast','subject_id'=>$i,'action'=>'ballast','created_at'=>1],range(1,300));
            foreach(array_chunk($rows,100)as$chunk){DB::table('domains_watch_staff_claims')->insert($chunk);}
        }
        WatchProfile::whereKey($id)->lockForUpdate()->firstOrFail();
        DB::table('domains_watch_names')->where('id',$watch)->update(['ns_profile_id'=>$id]);
        $signal('locked');$observe();
        if($case==='deadlock'){
            // Close the cycle: the staff write holds the settings rows and waits on this template row.
            try{App\Models\Setting::orderBy('id')->select('id')->lockForUpdate()->get();}
            catch(QueryException $e){throw new RuntimeException('The assignment holder was chosen as the deadlock victim: '.($e->errorInfo[1]??'unknown'));}
        }
        $wait('done');DB::rollBack();
    }
    echo json_encode(['complete'=>true,'phase'=>$phase,'case'=>$case,'action'=>$action])."\n";$nativeSuccessful=true;return;
}
expect($phase==='mutate','Known fixture phase');
$state=static fn():array=>['template'=>(array)DB::table('domains_watch_profiles')->where('id',$id)->first(),
    'audit'=>WatchAudit::where('subject_type','dns_profile')->where('subject_id',$id)->count(),
    'claims'=>DB::table('domains_watch_staff_claims')->where('subject_type','profile')->where('subject_id',$id)->count()];
$before=$state();same(1,(int)$before['template']['enabled'],'The template starts on');
$ticket=Writes::ticket((int)$manager->id,'profile',$id,(int)$before['template']['revision'],$action);
$connection=(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
// With snapshot isolation on, a locking read of a row changed after the read view fails instead of reading it.
same('OFF',DB::selectOne("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")?->Value??'OFF','Pinned MariaDB keeps repeatable-read locking reads');
$error=$code=$old=$stale=null;
$run=static function()use($writes,$manager,$id,$action,$ticket,&$error,&$code):void {
    try{$writes->run($manager,'profile',$id,$action,$ticket,'Synthetic contended template change');}
    catch(DomainException $e){$error=$e->getMessage();}catch(QueryException $e){$code=(int)($e->errorInfo[1]??0);}
};
if($case==='late'){
    // The older read view comes first; the assignment commits while the write waits for the template lock.
    DB::beginTransaction();
    $old=WatchName::whereKey($watch)->value('ns_profile_id');
    file_put_contents($root.'/'.$name.'-connection',$connection);$signal('started');$signal('snapshot');$wait('assigned');
    $run();
    $stale=WatchName::whereKey($watch)->value('ns_profile_id');
    DB::rollBack();
    $wait('committed');
    same($ids['other'],$old,'The older read view predates the assignment');
    same($id,(int)DB::table('domains_watch_names')->where('id',$watch)->value('ns_profile_id'),'The late assignment committed');
    same('This template is assigned to domains. Choose another template for them first.',$error,'The waiting '.$action.' reads the late assignment and refuses');
    same($ids['other'],$stale,'A plain read in the same view still misses the late assignment');
}else{
    if($case==='timeout'){DB::statement('SET SESSION innodb_lock_wait_timeout=4');}
    $wait('locked');file_put_contents($root.'/'.$name.'-connection',$connection);$signal('started');
    $run();$signal('done');
    same($case==='timeout'?1205:1213,$code,'The waiting '.$action.' ends with the '.$case.' error');
    same(0,DB::transactionLevel(),'The failed write leaves no open transaction');
}
same($before,$state(),'The '.$case.' '.$action.' leaves the template, its audit and the staff claim unchanged');
echo json_encode(['complete'=>true,'phase'=>$phase,'case'=>$case,'action'=>$action,'prefix'=>$prefix,
    'error'=>$error,'code'=>$code])."\n";$nativeSuccessful=true;
