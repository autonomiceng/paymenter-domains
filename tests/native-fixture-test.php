<?php
// Synthetic native harness probe only; no Domains entry point is installed.
declare(strict_types=1);
require __DIR__.'/native-app.php';
if (in_array('--fail-after-boot', $argv, true)) { throw new RuntimeException('synthetic native failure'); }

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

foreach (['users', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'settings'] as $table) {
    expect(Schema::hasTable($table), 'Native migration missing: '.$table);
}
$user = App\Models\User::create(['email' => 'fixture@example.invalid', 'password' => bcrypt('synthetic')]);
same('fixture@example.invalid', App\Models\User::findOrFail($user->id)->email, 'Native model readback');
try {
    DB::transaction(static function (): void {
        App\Models\User::create(['email' => 'rollback@example.invalid', 'password' => bcrypt('synthetic')]);
        throw new RuntimeException('synthetic rollback');
    });
} catch (RuntimeException $error) {
    same('synthetic rollback', $error->getMessage(), 'Transaction refusal');
}
same(1, App\Models\User::count(), 'Native transaction rolled back');
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains', false), 'Foundation has no entry point');
try { Illuminate\Support\Facades\Http::setHandler(static function (): never { throw new RuntimeException('Synthetic terminal handler should not run'); })->get('https://example.invalid'); expect(false, 'Stray HTTP escaped fixture'); }
catch (Illuminate\Http\Client\StrayRequestException) { /* expected */ }
echo "Native fixture: pinned upstream migrations, model, rollback and HTTP guard passed; no Domains install.\n";

$nativeSuccessful = true;
