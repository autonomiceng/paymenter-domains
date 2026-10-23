<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\ManagedPublicSchema;
return new class extends Migration {
    public function up(): void { ManagedPublicSchema::install(); }
    public function down(): void {} // Retain public facts, enrollment and continuity references.
};
