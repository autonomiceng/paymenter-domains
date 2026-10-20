<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\WatchScheduleSchema;
return new class extends Migration {
    public function up(): void { WatchScheduleSchema::install(); }
    public function down(): void {} // Retain independent public facts and recovery cursors.
};
