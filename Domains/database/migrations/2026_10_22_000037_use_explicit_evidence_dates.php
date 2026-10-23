<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\EvidenceDatesSchema;
return new class extends Migration {
    public function up(): void { EvidenceDatesSchema::upgrade(); }
    public function down(): void { /* Retain explicit evidence dates and their values. */ }
};
