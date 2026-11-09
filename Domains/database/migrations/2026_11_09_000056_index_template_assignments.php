<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\TemplateAssignmentIndex;
return new class extends Migration {
    public function up(): void { TemplateAssignmentIndex::install(); }
    public function down(): void {} // Retained assignments keep their index.
};
