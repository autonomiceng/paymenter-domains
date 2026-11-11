<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The installed fixture proves upgrade order, retained bytes and real query plans without provider access.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\Installation;
$indexes=[
    'domains_history_links'=>['domain_id','id'],
    'domains_watch_audit'=>['subject_type','subject_id','event','id'],
    'domains_domains'=>['server_id','id'],
    'domains_operations'=>['server_id','account_fingerprint','id'],
];
$install=new Installation;$install->install();
foreach($indexes as $table=>$columns){expect(Schema::hasIndex($table,$columns),'Fresh history/account index: '.$table);}
$migration='2026_11_11_000059_index_history_account_scans';
DB::table('domains_watch_audit')->insert(['subject_type'=>'watch','subject_id'=>7,'event'=>'staff_pause','after_revision'=>'2','data'=>'retained encrypted bytes','created_at'=>'2026-11-01 01:30:00']);
$before=DB::table('domains_watch_audit')->get()->toJson();
foreach($indexes as $table=>$columns){Schema::table($table,fn($t)=>$t->dropIndex($columns));}
DB::table('migrations')->where('migration',$migration)->delete();
$install->verify();$install->install();
foreach($indexes as $table=>$columns){expect(Schema::hasIndex($table,$columns),'Retained upgrade index: '.$table);}
same($before,DB::table('domains_watch_audit')->get()->toJson(),'Index upgrade preserves retained evidence');
$queries=[
    ['SELECT * FROM domains_history_links WHERE domain_id = 7 ORDER BY id','domains_history_links_domain_id_id_index'],
    ["SELECT * FROM domains_watch_audit WHERE subject_type = 'watch' AND subject_id = 7 AND event = 'staff_pause' ORDER BY id",'domains_watch_audit_subject_type_subject_id_event_id_index'],
    ['SELECT * FROM domains_domains WHERE server_id = 7 AND id > 1 ORDER BY id LIMIT 25','domains_domains_server_id_id_index'],
    ["SELECT * FROM domains_operations WHERE server_id = 7 AND account_fingerprint = 'account' AND id > 1 ORDER BY id LIMIT 25",'domains_operations_server_id_account_fingerprint_id_index'],
];
foreach($queries as [$sql,$name]){expect(str_contains(json_encode(DB::select('EXPLAIN QUERY PLAN '.$sql)),$name),'Query uses '.$name);}
$path=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
for($i=0;$i<2;$i++){(require $path)->up();$install->install();$install->verify();}
same($before,DB::table('domains_watch_audit')->get()->toJson(),'Reruns retain evidence');
Schema::table('domains_history_links',fn($t)=>$t->dropIndex($indexes['domains_history_links']));
$error=null;try{$install->verify();}catch(RuntimeException $e){$error=$e->getMessage();}
expect($error!==null,'Recorded history index loss is refused');
$nativeSuccessful=true;echo "History and account indexes: fresh, upgrade, plans, reruns and missing-index refusal.\n";
