<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\ManagedPublicWorkSchema;
return new class extends Migration {
    public function up(): void { ManagedPublicWorkSchema::install(); }
    public function down(): void {} // Retain enrollment and NS continuity references.
};
