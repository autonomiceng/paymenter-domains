<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Retained Laravel migration history and schema verification require the native SQLite fixture; no provider access.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,LiveReservationSchema};
(new Installation)->install();
$migration='2026_11_04_000054_add_live_catalogue_usage';
// Reconstruct the deployed pre-counter schema, retaining every original migration record and daily reservation.
if(Schema::hasColumn(LiveReservationSchema::TABLE,'catalogue_used')){Schema::table(LiveReservationSchema::TABLE,fn($t)=>$t->dropColumn('catalogue_used'));}
DB::table('migrations')->where('migration',$migration)->delete();
$rows=[['account'=>hash('sha256','retained-enom'),'day'=>'2026-11-04','used'=>909,'spend_micros'=>123456],
    ['account'=>hash('sha256',"namesilo\0live\0retained"),'day'=>'2026-11-03','used'=>95,'spend_micros'=>654321]];
DB::table(LiveReservationSchema::TABLE)->insert($rows);
(new Installation)->install();
expect(Schema::hasColumn(LiveReservationSchema::TABLE,'catalogue_used'),'Retained installation runs the new price-read counter migration');
same([0,0],DB::table(LiveReservationSchema::TABLE)->orderBy('day','desc')->pluck('catalogue_used')->map(fn($v)=>(int)$v)->all(),'All historical rows start as other work');
foreach($rows as $row){same($row,(array)DB::table(LiveReservationSchema::TABLE)->where('account',$row['account'])->first(array_keys($row)),'Retained total calls and exact spend are unchanged');}
expect(DB::table('migrations')->where('migration',$migration)->exists(),'The new migration is recorded');
DB::table(LiveReservationSchema::TABLE)->where('account',$rows[0]['account'])->update(['catalogue_used'=>7]);
(new Installation)->install();
same(7,(int)DB::table(LiveReservationSchema::TABLE)->where('account',$rows[0]['account'])->value('catalogue_used'),'Reinstall never resets the price-read count');
$file=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
(require $file)->up();
// A retained counter cannot be rolled back: Laravel must keep its migration record on refusal.
try {
    app(Illuminate\Database\Migrations\Migrator::class)->rollback([dirname($file)], ['step'=>1, 'force'=>true]);
    throw new RuntimeException('Rollback must refuse before Laravel removes the counter migration record');
} catch (LogicException) {}
expect(DB::table('migrations')->where('migration',$migration)->exists(),'Refused rollback retains its migration record');
(new Installation)->install();
same(7,(int)DB::table(LiveReservationSchema::TABLE)->where('account',$rows[0]['account'])->value('catalogue_used'),'Repeated migration and down retain usage');
DB::table('migrations')->where('migration',$migration)->delete();
try{(new Installation)->install();throw new LogicException('Unrecorded column was adopted');}
catch(DomainException $e){same('Retained Live catalogue usage has no installation record.',$e->getMessage(),'Partial restore with no migration record fails closed');}
DB::table('migrations')->insert(['migration'=>$migration,'batch'=>99]);
Schema::table(LiveReservationSchema::TABLE,fn($t)=>$t->dropColumn('catalogue_used'));
try{(new Installation)->install();throw new LogicException('Lost recorded column was silently recreated');}
catch(RuntimeException $e){same('Retained Live catalogue usage is missing.',$e->getMessage(),'A recorded but lost counter fails closed');}
same(2,DB::table(LiveReservationSchema::TABLE)->count(),'Neither partial restore refusal loses an account/day row');
echo "Live catalogue migration: retained counters, zero old price reads, reinstall and partial restores checked.\n";
$nativeSuccessful=true;
