<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\BalanceSchema;
return new class extends Migration {
    public function up(): void { BalanceSchema::install(); }
    public function down(): void {} // Retain the last known account balances.
};
