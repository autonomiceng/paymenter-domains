<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema;
return new class extends Migration { public function up(): void { StaffWatchSchema::install(); } public function down(): void {} };
