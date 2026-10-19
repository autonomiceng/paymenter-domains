<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\PslSchema;
return new class extends Migration {
    public function up(): void { PslSchema::install(); }
    public function down(): void {} // Keep both validated copies and update status.
};
