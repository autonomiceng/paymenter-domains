<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic retained upgrade only.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{BalanceSchema,BalanceAlertSchema};
// These unmodified migrations are the complete 9eb661b schema, before alert retry storage.
$paths=array_filter(glob(dirname(__DIR__).'/Domains/database/migrations/*.php'),static fn($p)=>basename($p)!=='2026_10_26_000041_create_balance_alerts.php');
app(Illuminate\Database\Migrations\Migrator::class)->run(array_values($paths),['force'=>true]);
DB::table(BalanceSchema::TABLE)->insert([
    ['server_id'=>7,'account_fingerprint'=>str_repeat('a',64),'amount'=>'123.456789','currency'=>'USD','read_at'=>1800000000,'attempted_at'=>1800000001,'source'=>'manual','last_error_code'=>null,'daily_due_at'=>1800086400,'manual_due_at'=>1800000060,'notified_on'=>'2027-01-15'],
    ['server_id'=>8,'account_fingerprint'=>str_repeat('b',64),'amount'=>null,'currency'=>null,'read_at'=>null,'attempted_at'=>1800000002,'source'=>'daily','last_error_code'=>'read_failed','daily_due_at'=>1800086402,'manual_due_at'=>0,'notified_on'=>null],
]);
$tables=array_map(static fn($table)=>$table['name'],Schema::getTables());
$before=[];foreach($tables as $table){if($table!=='migrations'){$before[$table]=DB::table($table)->get()->map(fn($row)=>(array)$row)->all();}}
$migrationPath=dirname(__DIR__).'/Domains/database/migrations/2026_10_26_000041_create_balance_alerts.php';
expect(is_file($migrationPath),'Balance alert retries require a new additive migration');
$migration=require $migrationPath;$migration->up();$migration->up();
foreach($before as $table=>$rows){same($rows,DB::table($table)->get()->map(fn($row)=>(array)$row)->all(),'Additive balance alert migration preserves every existing row: '.$table);}
BalanceAlertSchema::verify();same(0,DB::table(BalanceAlertSchema::TABLE)->count(),'Upgrade invents no alert or delivery');
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);
// Existing storage without its migration record must refuse implicit adoption.
$refused=false;try{$entry->installed();}catch(DomainException $e){$refused=$e->getMessage()==='Retained holding storage has no installation record.';}
expect($refused,'Retained alert retry storage requires its installation record');
DB::table('migrations')->insert(['migration'=>BalanceAlertSchema::MIGRATION,'batch'=>99]);$entry->installed();
DB::table(BalanceAlertSchema::TABLE)->insert(['server_id'=>7,'account_fingerprint'=>str_repeat('a',64),'pending_day'=>'2027-01-15','retry_at'=>1800000060,'attempts'=>1,'claim'=>str_repeat('c',32)]);
$pending=(array)DB::table(BalanceAlertSchema::TABLE)->sole();
$migration->down();$entry->uninstalled();$entry->installed();
same($pending,(array)DB::table(BalanceAlertSchema::TABLE)->sole(),'Rollback and reinstall retain pending alert retries');
Schema::table(BalanceAlertSchema::TABLE,fn($t)=>$t->dropColumn('retry_at'));
$refused=false;try{$entry->installed();}catch(RuntimeException $e){$refused=$e->getMessage()==='Retained Domains schema mismatch: '.BalanceAlertSchema::TABLE;}
expect($refused,'Recorded alert retry schema drift refuses reinstall');
$nativeSuccessful=true;echo "Balance alert additive upgrade preserves the signed wave 5 schema and all existing rows.\n";
