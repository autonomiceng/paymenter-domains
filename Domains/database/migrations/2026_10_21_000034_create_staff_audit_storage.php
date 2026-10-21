<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\StaffAuditSchema;
return new class extends Migration { public function up(): void { StaffAuditSchema::install(); } public function down(): void {} };
