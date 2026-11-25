<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/namesilo-test-activation-test.php';
$nativeSuccessful=false;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\Installation;
$key='domains_schema_verified';
$record=static fn()=>DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->where('key',$key);
$sourceVersion=\Paymenter\Extensions\Servers\Domains\Installation\Registry::version();
$version=$record()->value('value');
$failures=[];
$check=static function(bool $ok,string $why)use(&$failures):void {if(!$ok){$failures[]=$why;echo "FAIL: $why\n";}};
$check((json_decode((string)$version,true)['version']??$version)===$sourceVersion,'Fresh installation records the verified source version');
$measure=static function(callable $run):int {
    DB::flushQueryLog();DB::enableQueryLog();
    try{$run();}finally{$queries=DB::getQueryLog();DB::disableQueryLog();}
    return count(array_filter($queries,static fn(array $q):bool=>str_starts_with(strtolower($q['query']),'create table ')));
};
$toggle=static fn(bool $on)=>$writes->run($review('processing'),['confirmed'=>true,'enabled'=>$on]);
// Pin the record explicitly so this regression also exercises the old Orders path.
$record()->delete();DB::table('settings')->insert(['key'=>$key,'value'=>$version,'type'=>'string','encrypted'=>false]);
$toggle(false);
$check($measure(static fn()=>$toggle(true))===0,'Matching verification record skips reference rebuilds');
$toggle(false);
// A matching record must not bypass database guards against duplicate reservations or sends.
$drift=static function(callable $break,callable $restore,string $label)use($toggle,$measure,$check,$account,$record,$version):void {
    $break();$refused=false;
    try{
        $creates=$measure(static function()use($toggle,&$refused):void {
            try{$toggle(true);}catch(RuntimeException $error){$refused=true;}
        });
        $check($refused&&$creates>0&&!$account->fresh()->processing_enabled,'Matching record runs full verify and refuses '.$label);
    }finally{$restore();if($account->fresh()->processing_enabled){$toggle(false);}}
    (new Installation)->verify();
    $record()->update(['value'=>$version]);
};
foreach([['domains_domains',['reservation_name']],['domains_operations',['operation_key']],
    ['domains_operations',['invoice_item_id','action','retry_number']],['domains_runtime_operations',['operation_id']]] as [$table,$columns]){
    $name=$columns===['invoice_item_id','action','retry_number']?$table.'_debt_retry_u':$table.'_'.implode('_',$columns).'_unique';
    $drift(static fn()=>Schema::table($table,static fn($t)=>$t->dropUnique($name)),
        static fn()=>Schema::table($table,static fn($t)=>$t->unique($columns,$name)),'lost uniqueness on '.$table.'.'.implode(',',$columns));
}
$drift(static fn()=>Schema::table('domains_operations',static fn($t)=>$t->renameColumn('attempt','fixture_attempt')),
    static fn()=>Schema::table('domains_operations',static fn($t)=>$t->renameColumn('fixture_attempt','attempt')),'a changed evidence column');
$migration=DB::table('migrations')->where('migration','2026_11_23_000001_create_domains_schema')->sole();
DB::table('migrations')->where('id',$migration->id)->delete();$refused=false;
try{
    try{$toggle(true);}catch(RuntimeException $error){$refused=true;}
    $check($refused&&!$account->fresh()->processing_enabled,'Matching record refuses unrecorded shipped migrations');
}finally{DB::table('migrations')->insert((array)$migration);if($account->fresh()->processing_enabled){$toggle(false);}}
foreach(['missing','stale'] as $case){
    $record()->delete();
    if($case==='stale'){DB::table('settings')->insert(['key'=>$key,'value'=>'older-package','type'=>'string','encrypted'=>false]);}
    Schema::table('domains_domains',static fn($t)=>$t->dropUnique(['reservation_name']));
    $refused=false;
    $creates=$measure(static function()use($toggle,&$refused):void {
        try{$toggle(true);}catch(RuntimeException $error){$refused=str_contains($error->getMessage(),'schema mismatch');}
    });
    expect($refused&&$creates>0,'Missing or stale record invokes full verification and refuses broken schema: '.$case);
    expect(!$account->fresh()->processing_enabled,'Schema refusal leaves Orders off: '.$case);
    expect($record()->value('value')!==$version,'Failed verification never records success: '.$case);
    Schema::table('domains_domains',static fn($t)=>$t->unique('reservation_name'));
    expect($measure(static fn()=>$toggle(true))>0,'Unverified healthy schema still runs full verification: '.$case);
    $check($record()->value('value')===$version,'Successful fallback refreshes the verification record: '.$case);
    $toggle(false);
}
(new Installation)->verify();
$record()->update(['value'=>$version]);
Schema::rename('domains_operations','fixture_saved_operations');
try{
    $refused=false;
    try{$toggle(true);}catch(RuntimeException $error){$refused=true;}
    expect($refused&&!$account->fresh()->processing_enabled,'Matching record cannot accept a missing key table');
}finally{Schema::rename('fixture_saved_operations','domains_operations');}
expect($failures===[],implode('; ',$failures));
$nativeSuccessful=true;
echo "Orders verification record: ok\n";
