<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Retained Laravel migration history and schema verification require the native SQLite fixture; no provider access.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{ClosureCursorSchema,Installation};
(new Installation)->install();
$migration=ClosureCursorSchema::MIGRATION;$table=ClosureCursorSchema::TABLE;$column=ClosureCursorSchema::COLUMN;
expect(Schema::hasColumn($table,$column),'A fresh installation has the complimentary closure cursor');
// Reconstruct the deployed schema before the cursor, keeping every original migration record and queue row.
Schema::table($table,fn($t)=>$t->dropColumn($column));
DB::table('migrations')->where('migration',$migration)->delete();
DB::table($table)->insert(['server_id'=>4242,'account_fingerprint'=>str_repeat('a',64),'refund_cursor'=>17,'refund_turn'=>9]);
(new Installation)->install();
expect(Schema::hasColumn($table,$column),'A retained installation runs the cursor migration');
same([17,9,0],array_map('intval',array_values((array)DB::table($table)->where('server_id',4242)->first(['refund_cursor','refund_turn',$column]))),'Paid refund progress is kept and the closure page starts at the beginning');
expect(DB::table('migrations')->where('migration',$migration)->exists(),'The cursor migration is recorded');
DB::table($table)->where('server_id',4242)->update([$column=>31]);
(new Installation)->install();
same(31,(int)DB::table($table)->where('server_id',4242)->value($column),'Reinstall never resets the closure cursor');
$file=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
(require $file)->up();
try{(require $file)->down();throw new LogicException('Rollback was allowed');}catch(LogicException $e){expect($e->getMessage()!=='Rollback was allowed','Rollback is refused');}
same(31,(int)DB::table($table)->where('server_id',4242)->value($column),'Repeated migration and refused rollback keep the cursor');
DB::table('migrations')->where('migration',$migration)->delete();
try{(new Installation)->install();throw new LogicException('Unrecorded column was adopted');}
catch(DomainException $e){same('Retained complimentary closure cursor has no installation record.',$e->getMessage(),'A restored column with no migration record fails closed');}
DB::table('migrations')->insert(['migration'=>$migration,'batch'=>99]);
Schema::table($table,fn($t)=>$t->dropColumn($column));
try{(new Installation)->install();throw new LogicException('Lost recorded column was silently recreated');}
catch(RuntimeException $e){same('Retained complimentary closure cursor is missing.',$e->getMessage(),'A recorded but lost cursor fails closed');}
same(17,(int)DB::table($table)->where('server_id',4242)->value('refund_cursor'),'Neither refusal loses paid refund progress');
echo "Complimentary closure cursor migration: retained queues, reinstall and partial restores checked.\n";
$nativeSuccessful=true;
