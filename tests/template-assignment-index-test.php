<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,TemplateAssignmentIndex};
$installation=new Installation;$installation->install();
$indexed=static fn()=>array_map(fn($table)=>Schema::hasIndex($table,TemplateAssignmentIndex::COLUMNS[$table]),array_keys(TemplateAssignmentIndex::COLUMNS));
same([true,true,true],$indexed(),'A fresh install indexes DNS template assignments');
$migration=TemplateAssignmentIndex::MIGRATION;
$path=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
$history=DB::table('migrations')->where('migration','!=',$migration)->orderBy('id')->get()->toJson();
// An earlier install has the tables without these indexes or the migration record.
foreach(TemplateAssignmentIndex::COLUMNS as$table=>$columns){Schema::table($table,static fn(Blueprint $t)=>$t->dropIndex($columns));}
DB::table('migrations')->where('migration',$migration)->delete();
same([false,false,false],$indexed(),'The earlier install has no template assignment indexes');
$installation->verify();
$installation->install();same([true,true,true],$indexed(),'The upgrade adds the indexes before final schema verification');
require __DIR__.'/Support/installed-upgrade.php';
$source=dirname($path);assertInstalledMigrationOrder($source,$migration);
// A copy is not the tree the installer loaded, and a tree without the new migration cannot prove its order.
$refused=static function(string $tree)use($migration):string {
    try{assertInstalledMigrationOrder($tree,$migration);}catch(RuntimeException $e){return $e->getMessage();}
    throw new LogicException('Migration order accepted '.$tree);
};
$copy=sys_get_temp_dir().'/domains-migration-order-'.bin2hex(random_bytes(8));mkdir($copy,0700);
try{
    foreach(glob($source.'/*.php') as $file){copy($file,$copy.'/'.basename($file));}
    same('Migration order reads the tree the loaded installer migrates from',$refused($copy),'A copied migration tree is refused');
    unlink($copy.'/'.$migration.'.php');
    same('Migration order tree includes '.$migration,$refused($copy),'A tree without the new migration is refused');
}finally{foreach(glob($copy.'/*.php') as $file){unlink($file);}rmdir($copy);}
// The loaded tree with the new migration missing from the applied sequence is refused too.
$row=(array)DB::table('migrations')->where('migration',$migration)->sole();DB::table('migrations')->where('id',$row['id'])->delete();
try{same('Installed migrations applied exactly once in filename order, then '.$migration,$refused($source),'An upgrade without the new migration record is refused');}
finally{DB::table('migrations')->insert($row);}
same($history,DB::table('migrations')->where('migration','!=',$migration)->orderBy('id')->get()->toJson(),'The upgrade keeps earlier migration history');
$indexes=array_map(Schema::getIndexes(...),array_keys(TemplateAssignmentIndex::COLUMNS));$record=(array)DB::table('migrations')->where('migration',$migration)->sole();
for($i=0;$i<2;$i++){(require $path)->up();$installation->install();$installation->verify();}
same($indexes,array_map(Schema::getIndexes(...),array_keys(TemplateAssignmentIndex::COLUMNS)),'A repeated migration adds no duplicate index');
same($record,(array)DB::table('migrations')->where('migration',$migration)->sole(),'A repeated install keeps the migration record');
// A recorded migration with a lost index is refused.
Schema::table('domains_watch_product_maps',static fn(Blueprint $t)=>$t->dropIndex(TemplateAssignmentIndex::COLUMNS['domains_watch_product_maps']));
$error=null;try{$installation->verify();}catch(RuntimeException $e){$error=$e->getMessage();}
expect($error!==null,'A recorded migration without its index is refused');
$nativeSuccessful=true;echo "DNS template assignment indexes install and upgrade.\n";
