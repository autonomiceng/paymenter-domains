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
require __DIR__.'/Support/installed-upgrade.php';assertInstalledMigrationOrder();
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
