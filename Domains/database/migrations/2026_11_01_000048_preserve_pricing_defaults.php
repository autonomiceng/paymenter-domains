<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        // Freeze the former fallback, not the current runtime default. No TLD or publication writes.
        $legacy=['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none',
            'fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]];
        $last=DB::table('domains_accounts')->max('id');
        DB::table('domains_accounts')->where('id','<=',$last??0)->orderBy('id')->chunkById(100,static function($accounts)use($legacy):void {
            foreach($accounts as $account){
                DB::transaction(static function()use($account,$legacy):void {
                    $server=Server::whereKey($account->server_id)->lockForUpdate()->firstOrFail();
                    $server->settings()->firstOrCreate(['key'=>'domains_pricing_default_rule'],
                        ['type'=>'array','encrypted'=>false,'value'=>$legacy]);
                });
            }
        });
    }
    public function down(): void { /* Retain saved pricing choices. */ }
};
