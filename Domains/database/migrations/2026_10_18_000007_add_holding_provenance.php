<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema;
return new class extends Migration {
    public function up(): void { HoldingProvenanceSchema::install(); }
    public function down(): void {} // Retain provenance and history.
};
