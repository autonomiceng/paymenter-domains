<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\HistoryLinkSchema;
return new class extends Migration {
    public function up(): void { HistoryLinkSchema::install(); }
    public function down(): void { /* Retain invoice associations and evidence. */ }
};
