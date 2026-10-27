<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\NativeStock;
return new class extends Migration {
    public function up(): void { NativeStock::normalize(); }
    public function down(): void {} // Publication history and unlimited stock are retained.
};
