<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\ClosureCursorSchema;
return new class extends Migration {
    public function up(): void { ClosureCursorSchema::install(); }
    // Refuse rollback before Laravel deletes the migration record, so a retained column is never left unrecorded.
    public function down(): void { throw new \LogicException; }
};
