<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Run the actual installed observer and Blade assertions without a browser or external site shell.
// The native fixture is needed for encrypted records, runtime composition and Filament rendering.
$nativeFixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
if($nativeFixture===''||!is_dir($nativeFixture)){throw new RuntimeException('Native fixture required.');}
$render=$nativeFixture.'/runtime-records-render';
if(!mkdir($render)){throw new RuntimeException('Render directory could not be created.');}
$shell=$render.'/shell.html';
file_put_contents($shell,'<!doctype html><html lang="en"><head></head><body><main>@@SLOT@@</main></body></html>');
$argv=[__FILE__,$render,$shell];
require __DIR__.'/runtime-records-render.php';
