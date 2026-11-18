<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use App\Events\Invoice\GeneratePdf;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__).'/native-app.php';

// Keep native invoice and mail events while supplying an attachment for tests of other behavior.
Event::listen(GeneratePdf::class, static function (GeneratePdf $event): void {
    $event->setPdfFromContent("%PDF-1.4\n% Synthetic invoice attachment.\n%%EOF\n");
});
