<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\Installation;

$newMigration='2026_10_21_000034_create_staff_audit_storage';
$tables=['domains_staff_review_keys','domains_audit_archives'];
// Reproduce the pre-review-storage stack: all migrations through 000033,
// with the original five-table foundation. Remove the leaked declarations
// when running against the broken implementation so the regression goes red.
$paths=array_values(array_filter(glob(dirname(__DIR__).'/Domains/database/migrations/*.php'),
    static fn($path)=>basename($path,'.php')!==$newMigration));
app(Migrator::class)->run($paths,['force'=>true]);
foreach($tables as $table){Schema::dropIfExists($table);}
foreach(['domains_domains','domains_operations','domains_tlds','domains_accounts','domains_prices'] as $table){
    expect(Schema::hasTable($table),'Original foundation retained: '.$table);
}
$history=DB::table('migrations')->orderBy('id')->get()->toArray();
expect(DB::table('migrations')->where('migration','2026_10_20_000033_create_watch_staff_claims')->exists(),'Baseline history reaches 000033');
expect(!DB::table('migrations')->where('migration',$newMigration)->exists(),'Baseline has no new migration');
$installation=new Installation;
$installation->install();$installation->verify();
foreach($tables as $table){expect(Schema::hasTable($table),'Upgrade creates '.$table);}
expect(DB::table('migrations')->where('migration',$newMigration)->exists(),'Upgrade records additive migration');
foreach($history as $row){same((array)$row,(array)DB::table('migrations')->where('id',$row->id)->sole(),'Prior history preserved');}
DB::table($tables[0])->insert(['server_id'=>7,'review_key'=>str_repeat('a',32),'expires_at'=>1800000000]);
DB::table($tables[1])->insert(['server_id'=>7,'family'=>'staff_settings','payload'=>'synthetic retained ciphertext']);
$snapshot=static fn()=>array_map(static fn($table)=>DB::table($table)->get()->toJson(),$tables);
$rows=$snapshot();$migration=DB::table('migrations')->where('migration',$newMigration)->sole();
$installation->install();$installation->verify();
same($rows,$snapshot(),'Repeated upgrade retains review keys and archive bytes');
same((array)$migration,(array)DB::table('migrations')->where('id',$migration->id)->sole(),'Repeated upgrade is idempotent');

function auditRefused(callable $run,string $message): void
{
    try{$run();}
    catch(DomainException|RuntimeException $e){expect(str_contains($e->getMessage(),'Retained')||str_contains($e->getMessage(),'schema'),$message);return;}
    expect(false,$message);
}
// Each table alone without its migration must refuse before any schema repair.
DB::table('migrations')->where('id',$migration->id)->delete();
auditRefused(fn()=>$installation->install(),'Unrecorded complete pair refused');
foreach($tables as $missing){
    Schema::rename($missing,'retained_audit_fixture');
    try{
        auditRefused(fn()=>$installation->install(),'One unrecorded audit table refused');
        expect(!Schema::hasTable($missing),'Partial audit storage was not silently repaired');
        expect(!DB::table('migrations')->where('migration',$newMigration)->exists(),'Partial upgrade was not recorded');
    }finally{Schema::rename('retained_audit_fixture',$missing);}
}
DB::table('migrations')->insert((array)$migration);
foreach($tables as $missing){
    Schema::rename($missing,'retained_audit_fixture');
    try{auditRefused(fn()=>$installation->install(),'Recorded but lost audit table refused');}
    finally{Schema::rename('retained_audit_fixture',$missing);}
}
$installation->install();$installation->verify();
same($rows,$snapshot(),'Refused partial installations retain all evidence');
echo "Five-table foundation upgrade through 000033, additive audit storage, idempotency and partial-history refusals passed.\n";
$nativeSuccessful=true;
