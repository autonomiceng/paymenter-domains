<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Two native connections, no provider calls.
// Staff disable and delete actions wait for the registration default or template row lock.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
require_once __DIR__.'/FakeRegistrar.php';
use App\Models\{Role,User};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{DomainAudit,DnsTemplate};
use Paymenter\Extensions\Servers\Domains\Services\{BusinessSetting,Installation,SiteDnsDefault,DnsTemplateWrites as Writes};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$root=(string)getenv('TPL_BARRIERS');expect(is_dir($root),'Private barriers required');
$phase=getenv('TPL_PHASE');$case=(string)getenv('TPL_CASE');$action=(string)getenv('TPL_ACTION');$prefix=(string)getenv('SCHEMA_PREFIX');
$name=$case.'-'.$action;
$signal=static fn(string $step)=>file_put_contents($root.'/'.$name.'-'.$step,'ready');
$wait=static function(string $step)use($root,$name):void {
    $end=mariadbClock()+25;while(!is_file($root.'/'.$name.'-'.$step)){if(mariadbClock()>$end){throw new RuntimeException('Barrier timeout: '.$step);}usleep(10000);}
};
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false)as$table){Schema::rename($table,$prefix.$table);}}
}
config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');
app()->instance(Clock::class,new FakeClock(new DateTimeImmutable('2026-11-09T12:00:00Z')));
if($phase==='seed'){
    (new Installation)->install();
    DB::transaction(static fn()=>BusinessSetting::saveLocked(SiteDnsDefault::KEY,''));
    $role=Role::create(['name'=>'Synthetic template staff','permissions'=>[Staff::VIEW,Staff::POLICY,SiteDnsDefault::MANAGE,Writes::VIEW,Writes::MANAGE]]);
    $manager=User::create(['first_name'=>'Synthetic','last_name'=>'Staff','email'=>'template-staff@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
    $template=static fn(string $label)=>(int)DB::transaction(fn()=>DnsTemplate::create(['name'=>$label,'enabled'=>true,
        'nameservers'=>['ns1.spare.test','ns2.spare.test'],
        'edited_by'=>$manager->id,'reason'=>'Synthetic template setup']))->id;
    $ids=['manager'=>$manager->id,'other'=>$template('Current registration default')];
    SiteDnsDefault::save($manager,$ids['other']);
    foreach(['late','timeout','deadlock']as$kind){foreach(['disable','delete']as$verb){
        $ids[$kind.'-'.$verb]=$template('Locked '.$kind.' '.$verb);
    }}
    file_put_contents($root.'/ids.json',json_encode($ids,JSON_THROW_ON_ERROR));
    echo "Seeded DNS template lock fixtures.\n";$nativeSuccessful=true;return;
}
expect(in_array($case,['late','timeout','deadlock'],true)&&in_array($action,['disable','delete'],true),'Known lock case');
$ids=json_decode(file_get_contents($root.'/ids.json'),true,512,JSON_THROW_ON_ERROR);
$id=$ids[$name];$manager=User::findOrFail($ids['manager']);
$writes=new Writes;
if($phase==='hold'){
    // Verify the staff writer is blocked by this connection on the relevant row.
    $table=$case==='late'?'domains_business_settings':'domains_dns_templates';
    $lockTable='`'.DB::getDatabaseName().'`.`'.DB::getTablePrefix().$table.'`';
    $observe=static function()use($root,$name,$table,$lockTable,$wait):void {
        $wait('started');$writer=(int)file_get_contents($root.'/'.$name.'-connection');
        $waits=static fn():int=>(int)DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w'
            .' JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id'
            .' JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id'
            .' WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=CONNECTION_ID() AND l.lock_table=?',[$writer,$lockTable])->n;
        // InnoDB refreshes these tables only after 0.1 s without a read; faster polls re-read the old state.
        $end=mariadbClock()+20;
        while($waits()===0){if(mariadbClock()>$end){throw new RuntimeException('The staff write did not wait on the expected lock.');}usleep(200000);}
        file_put_contents($root.'/'.$name.'-lock-wait',$table);
    };
    if($case==='late'){
        // Save the registration default while the other connection holds its prior read view.
        $wait('snapshot');DB::beginTransaction();
        SiteDnsDefault::save($manager,$id);
        $signal('assigned');$observe();DB::commit();$signal('committed');
    }else{
        DB::beginTransaction();
        if($case==='deadlock'){
            // Enough undo makes InnoDB choose the lighter staff write as the deadlock victim.
            $rows=array_map(static fn($i)=>['claim'=>hash('sha256','ballast-'.$name.'-'.$i)],range(1,300));
            foreach(array_chunk($rows,100)as$chunk){DB::table('domains_dns_template_claims')->insert($chunk);}
        }
        DnsTemplate::whereKey($id)->lockForUpdate()->firstOrFail();
        $signal('locked');$observe();
        if($case==='deadlock'){
            // Deliberately reverse the supported order to prove rollback on an external deadlock.
            try{BusinessSetting::singleton(true);}
            catch(QueryException $e){throw new RuntimeException('The assignment holder was chosen as the deadlock victim: '.($e->errorInfo[1]??'unknown'));}
        }
        $wait('done');DB::rollBack();
    }
    echo json_encode(['complete'=>true,'phase'=>$phase,'case'=>$case,'action'=>$action])."\n";$nativeSuccessful=true;return;
}
expect($phase==='mutate','Known fixture phase');
$state=static fn():array=>['template'=>(array)DB::table('domains_dns_templates')->where('id',$id)->first(),
    'audit'=>DomainAudit::where('subject_type','dns_profile')->where('subject_id',$id)->count(),
    'claims'=>DB::table('domains_dns_template_claims')->count()];
$before=$state();same(1,(int)$before['template']['enabled'],'The template starts on');
$ticket=Writes::ticket((int)$manager->id,$id,(int)$before['template']['revision'],$action);
$connection=(string)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
// With snapshot isolation on, a locking read of a row changed after the read view fails instead of reading it.
same('OFF',DB::selectOne("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")?->Value??'OFF','Pinned MariaDB keeps repeatable-read locking reads');
$error=$code=$old=$stale=null;
$run=static function()use($writes,$manager,$id,$action,$ticket,&$error,&$code):void {
    try{$writes->run($manager,$id,$action,$ticket,'Synthetic contended template change');}
    catch(DomainException $e){$error=$e->getMessage();}catch(QueryException $e){$code=(int)($e->errorInfo[1]??0);}
};
if($case==='late'){
    // The older read view comes first; the assignment commits while the write waits for the registration default.
    DB::beginTransaction();
    $old=SiteDnsDefault::profile()?->id;
    file_put_contents($root.'/'.$name.'-connection',$connection);$signal('started');$signal('snapshot');$wait('assigned');
    $run();
    $stale=SiteDnsDefault::profile()?->id;
    DB::rollBack();
    $wait('committed');
    same($ids['other'],$old,'The older read view predates the assignment');
    same($id,SiteDnsDefault::profile()?->id,'The new registration default committed');
    same(SiteDnsDefault::REPLACE,$error,'Changing the current default is refused');
    same($ids['other'],$stale,'A plain read in the same view retains the prior default');
    SiteDnsDefault::save($manager,$ids['other']);
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
