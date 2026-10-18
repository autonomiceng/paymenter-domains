<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\SalesPolicySchema;

return new class extends Migration {
    public function up(): void { SalesPolicySchema::install(); }
    public function down(): void { /* Retain seller identity and revisions. */ }
};
