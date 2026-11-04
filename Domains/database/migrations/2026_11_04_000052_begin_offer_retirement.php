<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\OfferRetirement;
return new class extends Migration {
    public $withinTransaction = false;
    public function up(): void { OfferRetirement::begin(); }
    public function down(): void {}
};
