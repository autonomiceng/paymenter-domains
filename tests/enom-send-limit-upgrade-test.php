<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema as Tables,Installation};
(new Installation)->install();
function forgetLimiter(): void
{
    foreach(Tables::tables() as $table){Schema::dropIfExists($table);}
    DB::table('migrations')->where('migration',Tables::MIGRATION)->delete();
}
// An install from before the limiter: old counter and run budget tables, with rows and their migration records.
forgetLimiter();
foreach(Tables::RETIRED as $table){
    Schema::create($table,static function($t){$t->id();$t->unsignedInteger('used');});DB::table($table)->insert(['used'=>3]);
}
foreach(['2026_10_21_000035_create_enom_test_calls','2026_10_27_000042_create_enom_test_run_budget','2026_10_28_000043_create_enom_test_legacy_budget'] as $migration){
    expect(DB::table('migrations')->where('migration',$migration)->exists(),'Old migration stays recorded: '.$migration);
}
(new Installation)->install();
foreach(Tables::RETIRED as $table){expect(!Schema::hasTable($table),'Upgrade drops '.$table);}
Tables::verify();same(0,DB::table(Tables::SENDS)->count(),'The new count starts at zero');
expect(DB::table('migrations')->where('migration',Tables::MIGRATION)->exists(),'Limiter migration is recorded');
(new Installation)->install();Tables::verify();
// New tables with no migration record are a partial restore, not something to fill in.
DB::table('migrations')->where('migration',Tables::MIGRATION)->delete();
try{(new Installation)->install();throw new LogicException('unreachable');}
catch(DomainException $e){same('Retained TEST send history has no installation record.',$e->getMessage(),'Unrecorded send history refuses install');}
// Recorded tables that are lost or changed refuse install too, and the attempts that survive stay as they were.
DB::table('migrations')->insert(['migration'=>Tables::MIGRATION,'batch'=>99]);(new Installation)->install();
$account=str_repeat('a',64);DB::table(Tables::ACCOUNTS)->insert(['account'=>$account]);
DB::table(Tables::SENDS)->insert(['account'=>$account,'command'=>'GetBalance','sent_at'=>1]);
function refusesDamage(string $table,string $case,Closure $damage,Closure $repair): void
{
    $damage();
    try{(new Installation)->install();throw new LogicException('unreachable');}
    catch(RuntimeException $e){same('Retained Domains schema mismatch: '.$table,$e->getMessage(),$case.' refuses install');}
    $repair();
    same([1,1],[DB::table(Tables::ACCOUNTS)->count(),DB::table(Tables::SENDS)->count()],$case.' changes no attempt');
}
foreach(Tables::tables() as $table){
    refusesDamage($table,'Missing '.$table,fn()=>Schema::rename($table,$table.'_moved'),fn()=>Schema::rename($table.'_moved',$table));
}
refusesDamage(Tables::SENDS,'A dropped send index',fn()=>Schema::table(Tables::SENDS,fn($t)=>$t->dropIndex(['account','sent_at'])),
    fn()=>Schema::table(Tables::SENDS,fn($t)=>$t->index(['account','sent_at'])));
(new Installation)->install();Tables::verify();
echo "Enom TEST send limit upgrade: retired tables dropped, count starts at zero, reinstall, partial restore and damaged tables checked.\n";
$nativeSuccessful=true;
