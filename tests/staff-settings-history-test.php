<?php
// Indexed replay and bounded encrypted history; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\{Auth,Crypt,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,StaffAuditSchema,StaffSettingsReview as Review};
extract(commerceFixture());Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$u=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('default_rule')]);Auth::setUser($u);
$review=static fn(?string $ticket=null)=>new Review($u->id,$t->server_id,$t->id,'default_rule',$ticket??Review::ticket($u->id,$t->server_id,$t->id,'default_rule',Review::stamp($t->server_id,$t->id)),'Retain synthetic audit evidence');
$unverified=$review();
$inFlight=static function(Review $r):void {DB::transaction(function()use($r):void {$r->lockActor();$r->lockAccounts();$r->check(true);});};
tldRefusal(fn()=>$inFlight($unverified),'Verified in-flight review required.');
$firstTicket=Review::ticket($u->id,$t->server_id,$t->id,'default_rule',Review::stamp($t->server_id,$t->id));
$first=$review($firstTicket);$first->local(static fn()=>null);$stamp=Review::stamp($t->server_id,$t->id);
$server=Server::findOrFail($t->server_id);$batch=$server->settings()->where('key',Review::KEY)->sole();
$prototype=['server_id'=>$server->id,'payload'=>Crypt::encryptString($batch->value)];
$inFlight($first);
$fresh=$review($firstTicket);
tldRefusal(fn()=>$inFlight($fresh),'Verified in-flight review required.');
// Old archive corruption must not be decrypted by the write or recent-history display.
function seedArchives(array $row,int $from,int $through): void
{
    for($i=$from;$i<=$through;$i++){
        foreach(['staff_settings','reminders'] as $family){DB::table(AuditArchives::TABLE)->insert([...$row,'family'=>$family]);}
    }
}
$retrieved=0;
Setting::retrieved(static function(Setting $s)use(&$retrieved):void {if($s->encrypted){$retrieved++;}});
function historyCost(callable $run,int &$reads): array
{
    $reads=0;DB::enableQueryLog();DB::flushQueryLog();$run();$queries=DB::getQueryLog();DB::disableQueryLog();
    return [count($queries),$reads];
}
seedArchives($prototype,1,10);
$writeTen=historyCost(fn()=>$review()->local(static fn()=>null),$retrieved);
$readTen=historyCost(fn()=>TldFacts::history($server->id,$t->id),$retrieved);
$nativeLoad=static fn()=>DB::transaction(fn()=>Server::whereKey($server->id)->lockForUpdate()->firstOrFail()->settings()->lockForUpdate()->get());
$nativeTen=historyCost($nativeLoad,$retrieved);
seedArchives($prototype,11,500);
$writeMany=historyCost(fn()=>$review()->local(static fn()=>null),$retrieved);
$readMany=historyCost(fn()=>TldFacts::history($server->id,$t->id),$retrieved);
$nativeMany=historyCost($nativeLoad,$retrieved);
same($nativeTen,$nativeMany,'Native full-settings locked loads do not grow with either archive family');
same($writeTen,$writeMany,'10 versus 500 archives have constant write queries and setting decrypts');
same($readTen,$readMany,'10 versus 500 archives have constant display queries and setting decrypts');
expect($writeMany[1]>0&&$readMany[1]>0,'Encrypted setting retrieval counter is active');
same(1,$readMany[1],'History display retrieves only the current encrypted native setting');
DB::table(AuditArchives::TABLE)->where('id',DB::table(AuditArchives::TABLE)->min('id'))->update(['payload'=>'broken old ciphertext']);
$review()->local(static fn()=>null);
expect(!str_contains(json_encode(TldFacts::history($server->id)),'unavailable'),'Old archives are retained without being loaded');
expect(count(Review::historyRows($server))<=Review::HISTORY_LIMIT,'History display has an explicit entry bound');
same($stamp,Review::stamp($t->server_id,$t->id),'Audit growth leaves the reviewed state unchanged');
tldRefusal(fn()=>$first->local(static fn()=>null),'Review key used.');
$keyRows=DB::table(Review::KEYS)->get()->toJson();$archiveRows=DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson();
$migration=require dirname(__DIR__).'/Domains/database/migrations/2026_10_21_000034_create_staff_audit_storage.php';
$migration->down();$migration->up();StaffAuditSchema::verify();
same($keyRows,DB::table(Review::KEYS)->get()->toJson(),'Reinstall retains used keys');
same($archiveRows,DB::table(AuditArchives::TABLE)->orderBy('id')->get()->toJson(),'Reinstall retains encrypted archive bytes');
foreach([Review::KEYS,AuditArchives::TABLE] as $table){
    Schema::rename($table,'retained_audit_fixture');
    try{StaffAuditSchema::install();expect(false,'Missing retained audit table accepted');}
    catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Missing audit storage refuses reinstall');}
    finally{Schema::rename('retained_audit_fixture',$table);}
}
StaffAuditSchema::verify();
$used=DB::table(Review::KEYS)->first();
try{DB::table(Review::KEYS)->insert((array)$used);expect(false,'Duplicate key accepted');}
catch(Illuminate\Database\QueryException){}
$failed=$review();
try{$failed->local(static fn()=>throw new DomainException('Synthetic write failure'));}
catch(DomainException $e){same('Synthetic write failure',$e->getMessage(),'Write failure retained');}
tldRefusal(fn()=>$inFlight($failed),'Verified in-flight review required.');
$failed->local(static fn()=>null); // Failed local transaction rolls key consumption back too.
// Select the latest batch for the right family, then enforce the entry cap across both batches.
$template=json_decode($batch->value,true)[0];$template['before']=[];$template['after']=[];
$rows=static fn($label,$count)=>array_map(static fn($i)=>[...$template,'key'=>$label.$i,'reason'=>$label.$i],range(0,$count-1));
DB::transaction(fn()=>AuditArchives::append($server->id,'staff_settings',json_encode($rows('latest archive ',60))));
DB::transaction(fn()=>AuditArchives::append($server->id,'reminders',json_encode(['unrelated'=>'newer reminder archive'])));
$batch->value=json_encode($rows('current ',10));$batch->save();$window=Review::historyRows($server);
same(50,count($window),'History caps the combined batches');same('latest archive 20',$window[0]['reason'],'History uses the newest inserted archive');same('current 9',$window[49]['reason'],'Current batch follows the archive');
$batch->value=json_encode($rows('current ',60));$batch->save();$window=Review::historyRows($server);
same(50,count($window),'Current batch alone is capped');same('current 10',$window[0]['reason'],'Oldest current entries omitted');
echo 'History 10/500 archives write '.json_encode([$writeTen,$writeMany]).' / display '.json_encode([$readTen,$readMany])." queries/decrypts\n";
echo 'Native full-settings 10/500 archives '.json_encode([$nativeTen,$nativeMany])." queries/decrypts\n";
echo "Indexed replay, retained unique keys, rollback and bounded recent history passed.\n";$nativeSuccessful=true;
