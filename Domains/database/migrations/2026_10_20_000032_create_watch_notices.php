<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema;
return new class extends Migration {
    public function up(): void { WatchNoticeSchema::install(); }
    public function down(): void { /* Notice and channel evidence are retained. */ }
};
