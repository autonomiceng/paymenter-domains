<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Full native panel and customer renders retain several page trees.
ini_set('memory_limit','256M');
$renderRoot=getenv('PAYMENTER_TEST_FIXTURE').'/refusal-render';
if(!mkdir($renderRoot)){throw new RuntimeException('Render directory required.');}
file_put_contents($renderRoot.'/shell.html','<!doctype html><html><head></head><body>@@SLOT@@</body></html>');
$argv=[__FILE__,$renderRoot,$renderRoot.'/shell.html'];
require __DIR__.'/runtime-render.php';
