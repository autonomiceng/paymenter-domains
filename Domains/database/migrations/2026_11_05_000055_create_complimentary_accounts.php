<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccountSchema;
return new class extends Migration {
    public function up():void {ComplimentaryAccountSchema::install();}
    public function down():void {} // Financial authorizations are retained.
};
