<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\HoldingAdoptionSchema;
return new class extends Migration {
    public function up(): void { HoldingAdoptionSchema::install(); }
    public function down(): void {} // Retain original holding provenance.
};
