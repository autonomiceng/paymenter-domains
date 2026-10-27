<?php
// Offline operations-only runtime regression.
declare(strict_types=1);
require __DIR__.'/runtime-presenter-test.php';
$nativeSuccessful=false;
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{RuntimeWork,RuntimePorts};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;

Queue::fake();
$domain=Domain::orderBy('id')->firstOrFail();
$original=(array)DB::table('domains_operations')->where('domain_id',$domain->id)->first();
unset($original['id']);
$ids=[];
for($i=0;$i<28;$i++){
    $ids[]=DB::table('domains_operations')->insertGetId([...$original,'invoice_item_id'=>null,
        'operation_key'=>'operation-sweep-'.$i,'state'=>'queued']);
}
$tables=['domains_domains','domains_tlds','domains_prices','domains_observation_queues',
    'domains_reminder_schedules','invoices','invoice_items','invoice_transactions','services','credits'];
$snapshot=static fn()=>array_map(static fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),$tables);
$before=$snapshot();
$ports=new RuntimePorts;$extra=0;
$ports->registerInitialManagedRecordObservations(static function()use(&$extra):void{$extra++;});
$runtime=new RuntimeWork($clock,$ports);
$runtime->tickOperations();
DB::table('domains_runtime_turns')->where('server_id',$domain->server_id)->update(['domain_cursor'=>71,'tld_cursor'=>83]);
same(25,Queue::pushed(RunOperation::class)->count(),'Operations-only turn keeps the existing 25-operation bound');
same($ids[24],(int)DB::table('domains_runtime_turns')->where('server_id',$domain->server_id)->value('operation_cursor'),'Durable cursor retains the first batch');
$runtime->tickOperations();
same(25,Queue::pushed(RunOperation::class)->count(),'An early repeated turn remains deferred');
$clock->instant=$clock->now()->modify('+61 seconds');
$runtime->tickOperations();
same($ids,Queue::pushed(RunOperation::class)->map(static fn($job)=>$job->operationId)->all(),'Next due turn reaches every remaining operation');
same(0,(int)DB::table('domains_runtime_turns')->where('server_id',$domain->server_id)->value('operation_cursor'),'Completed operation scan wraps');
same(71,(int)DB::table('domains_runtime_turns')->max('domain_cursor'),'Operation mode leaves domain scans untouched');
same(83,(int)DB::table('domains_runtime_turns')->max('tld_cursor'),'Operation mode leaves catalogue scans untouched');
same(0,$extra,'Operation mode does not run independent observation producers');
same($before,$snapshot(),'Operation mode does not enroll observations, reminders, change prices or run billing');
$clock->instant=$clock->now()->modify('+61 seconds');
$runtime->tick();
same(1,DB::table('domains_reminder_schedules')->where('server_id',$domain->server_id)->count(),'Full runtime repairs missing reminder enrollment after operations-only mode');
same(1,DB::table('domains_observation_queues')->where('server_id',$domain->server_id)->count(),'Full runtime repairs missing observation enrollment after operations-only mode');
echo "Bounded operations-only dispatch, durable backoff and producer isolation passed.\n";
$nativeSuccessful=true;
