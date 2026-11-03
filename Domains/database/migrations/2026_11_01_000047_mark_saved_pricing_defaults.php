<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    /** Runs before 000048. A 30% someone saved and the 30% it writes are the same row, so 000049 needs this line between them. */
    public function up(): void
    {
        DB::table('settings')->insert(['key'=>'domains_pricing_saved_through','value'=>(string)(DB::table('settings')->max('id')??0),'type'=>'string','encrypted'=>false,'settingable_type'=>null,'settingable_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { /* 000049 removes the mark. */ }
};
