<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Retained Laravel migration history and schema verification require the native SQLite fixture; no provider access.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryTimeSchema,Installation};
(new Installation)->install();
$migration=ComplimentaryTimeSchema::MIGRATION;
// Reconstruct the deployed schema without the zone column, keeping retained store-zone rows.
foreach(ComplimentaryTimeSchema::TABLES as $table){if(Schema::hasColumn($table,'time_zone')){Schema::table($table,fn($t)=>$t->dropColumn('time_zone'));}}
DB::table('migrations')->where('migration',$migration)->delete();
$account=['user_id'=>7,'actor_id'=>3,'enabled'=>true,'reason'=>'Retained change','created_at'=>'2026-11-01 01:30:00'];
$approval=['operation_id'=>9,'amount'=>'8.0040','actor_id'=>3,'reason'=>'Retained approval','created_at'=>'2026-11-01 01:30:00'];
DB::table('domains_complimentary_accounts')->insert($account);DB::table('domains_complimentary_approvals')->insert($approval);
(new Installation)->install();
foreach(ComplimentaryTimeSchema::TABLES as $table){expect(Schema::hasColumn($table,'time_zone'),'Retained installation adds the zone column: '.$table);}
expect(DB::table('migrations')->where('migration',$migration)->exists(),'The new migration is recorded');
$saved=static fn(string $table)=>array_values((array)DB::table($table)->sole(['created_at','reason','time_zone']));
same(['2026-11-01 01:30:00','Retained change',null],$saved('domains_complimentary_accounts'),'A retained change is not rewritten and has no zone');
same(['2026-11-01 01:30:00','Retained approval',null],$saved('domains_complimentary_approvals'),'A retained approval is not rewritten and has no zone');
// A retained row reads in the store zone, a UTC row reads as UTC, whatever the store zone is.
$prior=date_default_timezone_get();
try{date_default_timezone_set('America/Los_Angeles');
    same('2026-11-01T08:30:00+00:00',ComplimentaryAccounts::instant('2026-11-01 01:30:00')->format(DATE_ATOM),'A retained row reads in the store zone');
    same('2026-11-01T09:30:00+00:00',ComplimentaryAccounts::instant('2026-11-01 09:30:00','UTC')->format(DATE_ATOM),'A UTC row reads as UTC');
}finally{date_default_timezone_set($prior);}
(new Installation)->install();
same(1,DB::table('domains_complimentary_accounts')->count(),'Reinstall keeps retained rows');
$file=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
(require $file)->up();
try {
    app(Illuminate\Database\Migrations\Migrator::class)->rollback([dirname($file)], ['step'=>1, 'force'=>true]);
    throw new RuntimeException('Rollback must refuse before Laravel removes the zone migration record');
} catch (LogicException) {}
expect(DB::table('migrations')->where('migration',$migration)->exists(),'Refused rollback retains its migration record');
DB::table('migrations')->where('migration',$migration)->delete();
try{(new Installation)->install();throw new LogicException('Unrecorded column was adopted');}
catch(DomainException $e){same('Retained complimentary time zones have no installation record.',$e->getMessage(),'Partial restore with no migration record fails closed');}
DB::table('migrations')->insert(['migration'=>$migration,'batch'=>99]);
Schema::table('domains_complimentary_approvals',fn($t)=>$t->dropColumn('time_zone'));
try{(new Installation)->install();throw new LogicException('Lost recorded column was silently recreated');}
catch(RuntimeException $e){same('Retained complimentary time zones are missing.',$e->getMessage(),'A recorded but lost column fails closed');}
same([1,1],[DB::table('domains_complimentary_accounts')->count(),DB::table('domains_complimentary_approvals')->count()],'Neither partial restore refusal loses a row');
echo "Complimentary time zone migration: retained rows unchanged, reinstall and partial restores checked.\n";
$nativeSuccessful=true;
