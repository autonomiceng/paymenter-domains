<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// These native financial scenarios assert invoices/events, not PDF layout. Keep
// their real event path with a bounded attachment instead of rendering every PDF.
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\GeneratePdf::class,static function(App\Events\Invoice\GeneratePdf $event):void {
    $event->setPdfFromContent("%PDF-1.4\n% Synthetic native-fixture invoice attachment.\n%%EOF\n");
});
