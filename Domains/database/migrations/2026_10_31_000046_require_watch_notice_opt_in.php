<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticeSchema;
return new class extends Migration {
    public function up(): void { WatchNoticeSchema::requireOptIn(); }
    public function down(): void { /* Retain customer preferences and opt-in defaults. */ }
};
