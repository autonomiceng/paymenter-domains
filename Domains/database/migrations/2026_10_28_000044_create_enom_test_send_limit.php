<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimitSchema;
return new class extends Migration {
    // Replaces the daily counter and run budget; the new count starts at zero.
    public function up(): void { EnomTestSendLimitSchema::install(); }
    public function down(): void {} // Retain send attempts on uninstall/reinstall.
};
