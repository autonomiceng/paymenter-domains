<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema;
return new class extends Migration {
    public function up(): void { EnomTestLegacyBudgetSchema::install(); }
    public function down(): void {} // Retained proof and unknown-date debt never reset.
};
