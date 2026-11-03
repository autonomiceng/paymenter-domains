<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\OfferSchema;
return new class extends Migration {
    public function up(): void { OfferSchema::install(); }
    public function down(): void {} // Retained data is never destructively rolled back.
};
