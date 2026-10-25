<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema,Installation};
$installation=new Installation;$installation->install();
$hasIndex=static fn()=>Schema::hasIndex('domains_operations',['service_id']);
expect($hasIndex(),'Fresh operations schema indexes service_id');
$migration='2026_10_24_000040_index_operation_services';
$path=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
$history=DB::table('migrations')->where('migration','!=',$migration)->orderBy('id')->get()->toJson();
$rows=static fn()=>DB::table('domains_operations')->orderBy('id')->get()->toJson();
// Retain an actual operation, including opaque evidence, across the older schema upgrade.
DB::table('domains_operations')->insert(['server_id'=>1,'registrar_key'=>'enom','account_fingerprint'=>str_repeat('a',64),
    'domain_id'=>2,'service_id'=>3,'operation_key'=>'retained-index-upgrade','action'=>'register',
    'state'=>'held','currency'=>'USD','payload'=>'retained ciphertext','attempt'=>'retained attempt']);
$before=$rows();Schema::table('domains_operations',static fn(Blueprint $t)=>$t->dropIndex(['service_id']));
DB::table('migrations')->where('migration',$migration)->delete();
expect(!$hasIndex(),'Older fixture has no service index');
$installation->install();expect($hasIndex(),'Upgrade adds service index before final schema verification');
require __DIR__.'/Support/installed-upgrade.php';assertInstalledMigrationOrder();
same($before,$rows(),'Upgrade retains operation identities and evidence');
same($history,DB::table('migrations')->where('migration','!=',$migration)->orderBy('id')->get()->toJson(),'Upgrade retains earlier migration history');
$indexes=Schema::getIndexes('domains_operations');$record=(array)DB::table('migrations')->where('migration',$migration)->sole();
for($i=0;$i<2;$i++){(require $path)->up();$installation->install();$installation->verify();}
same($indexes,Schema::getIndexes('domains_operations'),'Repeated additive migration creates no duplicate indexes');
same($record,(array)DB::table('migrations')->where('migration',$migration)->sole(),'Repeated installation keeps migration record');
(require $path)->down();same($indexes,Schema::getIndexes('domains_operations'),'Rollback retains service index');same($before,$rows(),'Rollback retains operations');
// Equivalent indexes may already have another name on an existing installation.
Schema::table('domains_operations',static function(Blueprint $t):void{$t->dropIndex(['service_id']);$t->index('service_id','retained_service_index');});
$indexes=Schema::getIndexes('domains_operations');(require $path)->up();
same($indexes,Schema::getIndexes('domains_operations'),'Existing equivalent service index is retained');$installation->verify();
Schema::table('domains_operations',static fn(Blueprint $t)=>$t->dropIndex('retained_service_index'));
try{$installation->install();expect(false,'Recorded index migration must not repair missing index');}
catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Recorded index drift fails closed');}
// Pending upgrades cannot hide unrelated retained-schema drift.
DB::table('migrations')->where('migration',$migration)->delete();
Schema::table('domains_operations',static fn(Blueprint $t)=>$t->string('unexpected')->nullable());
try{$installation->install();expect(false,'Pending index migration accepted unrelated drift');}
catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Pending upgrade refuses unrelated drift');}
Schema::table('domains_operations',static fn(Blueprint $t)=>$t->dropColumn('unexpected'));
$installation->install();FoundationSchema::verify();same($before,$rows(),'Retry preserves retained operation evidence');
echo "Fresh and retained operation service index, repeated upgrades, rollback and schema refusals passed.\n";
$nativeSuccessful=true;
