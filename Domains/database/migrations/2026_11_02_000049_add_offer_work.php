<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\OfferWorkSchema;
return new class extends Migration {
    public function up(): void { OfferWorkSchema::install(); }
    public function down(): void {} // Retained data is never destructively rolled back.
};
