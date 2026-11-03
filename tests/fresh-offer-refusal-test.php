<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Setting;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Services\Installation;

$entry = new Domains;
$legacy = static function (string $why): void {
    same('legacy', UpgradeGate::routeState()['state'], $why.' cannot take the fresh shortcut');
    same(0, Setting::where('key',UpgradeGate::KEY)->whereNull('settingable_type')->count(), $why.' cannot create an initialization intent');
};
// Soft-deleted server identity is still durable installation history.
$extensionId = DB::table('extensions')->insertGetId(['name'=>'Retained deleted Domains','type'=>'server',
    'extension'=>'Domains','enabled'=>false,'deleted_at'=>'2026-01-01 00:00:00']);
$extension = (array) DB::table('extensions')->where('id',$extensionId)->sole();
try { $entry->installed(); } catch (DomainException) {}
$legacy('Deleted Domains server identity');
same($extension, (array) DB::table('extensions')->where('id',$extensionId)->sole(), 'Deleted identity evidence is preserved');
DB::table('extensions')->where('id',$extensionId)->delete();
$payload = json_encode(['displayName'=>'Paymenter\\Extensions\\Servers\\Domains\\Jobs\\SyncPrices']);
$jobId = DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>$payload,'attempts'=>0,'available_at'=>1,'created_at'=>1]);
try { $entry->installed(); } catch (DomainException) {}
$legacy('Queued Domains work');
same($payload, DB::table('jobs')->where('id',$jobId)->value('payload'), 'Queued work remains intact');
DB::table('jobs')->where('id',$jobId)->delete();
// Retained orphan settings include scoped rows whose key happens to match the global gate.
foreach ([['key'=>'domains_catalogue_acceptance_orphan'],
    ['key'=>UpgradeGate::KEY,'settingable_type'=>'server','settingable_id'=>999]] as $attributes) {
    $row = Setting::create([...$attributes,'type'=>'string','encrypted'=>true,'value'=>'synthetic retained evidence']);
    $before = (array) DB::table('settings')->where('id',$row->id)->sole();
    try { $entry->installed(); } catch (DomainException) {}
    $legacy('Orphan settings');
    same($before, (array) DB::table('settings')->where('id',$row->id)->sole(), 'Orphan evidence remains byte-for-byte intact');
    $row->delete();
    // The old installer may have installed a legacy empty schema; that is still retained, never fresh.
}
// Start a separate holding-only retained fixture in this synthetic database.
foreach (array_reverse(Schema::getTableListing(null,false)) as $table) {
    if (str_starts_with($table,'domains_')) { Schema::drop($table); }
}
$files = app(Illuminate\Database\Migrations\Migrator::class)->getMigrationFiles([dirname(__DIR__).'/Domains/database/migrations']);
DB::table('migrations')->whereIn('migration',array_keys($files))->delete();
Schema::create('domains_domains', static function (Blueprint $t): void { $t->id(); $t->text('snapshot'); });
DB::table('domains_domains')->insert(['snapshot'=>'synthetic retained holding']);
try { $entry->installed(); throw new LogicException('Holding-only installation unexpectedly accepted'); }
catch (DomainException) {}
$legacy('Holding-only state');
same('synthetic retained holding', DB::table('domains_domains')->sole()->snapshot, 'Holding-only evidence survives refusal');
expect(!Schema::hasTable('domains_accounts'), 'Partial holding-only state is not silently completed');
Schema::drop('domains_domains');
// Schema maintenance represents an already installed, empty C6 database, never new-install authority.
(new Installation)->install(); $before = DB::table('migrations')->orderBy('id')->get()->toJson();
$entry->installed(); $entry->upgraded('0.1.0');
$legacy('Old empty installation');
same($before, DB::table('migrations')->orderBy('id')->get()->toJson(), 'Old empty history is unchanged');
$record = DB::table('migrations')->where('migration','2026_10_18_000001_create_domains_foundation')->sole();
DB::table('migrations')->where('id',$record->id)->delete();
try { $entry->installed(); throw new LogicException('Partial migration history unexpectedly accepted'); }
catch (DomainException) {}
$legacy('Partial migration history');
expect(Schema::hasTable('domains_accounts'), 'Partial history refusal retains its existing schema');
$nativeSuccessful = true;
