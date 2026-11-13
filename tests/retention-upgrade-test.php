<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,RetentionSchema,RetentionOwnerIndex};
$install=new Installation();$install->install();
expect(Schema::hasIndex('domains_watch_audit',['user_id','id']),'Customer history cleanup has an owner-leading index');
$tables=RetentionSchema::tables();$migration=RetentionSchema::MIGRATION;
foreach(RetentionOwnerIndex::TABLES as $table){Schema::table($table,static fn($t)=>$t->dropIndex(RetentionOwnerIndex::COLUMNS));}
DB::table('migrations')->where('migration',RetentionOwnerIndex::MIGRATION)->delete();
foreach($tables as $table){Schema::drop($table);}DB::table('migrations')->where('migration',$migration)->delete();
$install->install();$install->verify();
foreach(RetentionOwnerIndex::TABLES as $table){expect(Schema::hasIndex($table,RetentionOwnerIndex::COLUMNS),'Owner cleanup has a narrow index: '.$table);}
DB::table(RetentionSchema::KEYS)->insert(['family'=>'notice','replay_key'=>str_repeat('a',64)]);
$before=DB::table(RetentionSchema::KEYS)->get()->toJson();$install->install();same($before,DB::table(RetentionSchema::KEYS)->get()->toJson(),'Reinstall preserves replay identities');
Schema::rename(RetentionSchema::MARKS,'domains_retention_saved_marks');
try{$install->install();expect(false,'Recorded partial schema must refuse');}catch(RuntimeException $e){expect(!Schema::hasTable(RetentionSchema::MARKS),'Missing retained table was not silently recreated');}
finally{Schema::rename('domains_retention_saved_marks',RetentionSchema::MARKS);}
$install->verify();
DB::table('migrations')->where('migration',$migration)->delete();
try{$install->install();expect(false,'Unrecorded retained schema must refuse');}catch(DomainException $e){expect(str_contains($e->getMessage(),'installation record'),'Exact retained-history boundary');}
$down=require dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
try{$down->down();expect(false,'Retention rollback must refuse');}catch(RuntimeException $e){same('Retained history cannot be rolled back.',$e->getMessage(),'Rollback retains evidence');}
same($before,DB::table(RetentionSchema::KEYS)->get()->toJson(),'Failed install and rollback preserve replay rows');
echo "Retention upgrades create additive storage, preserve replay rows, and refuse partial or unrecorded history.\n";
$nativeSuccessful=true;
