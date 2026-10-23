<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

function writeRenderArtifact(string $path, string $content): void
{
    $completed = $GLOBALS['nativeSuccessful'] ?? false;
    $GLOBALS['nativeSuccessful'] = false;
    expect(file_put_contents($path, $content) !== false, 'Unable to write render artifact: '.$path);
    $GLOBALS['nativeSuccessful'] = $completed;
}
