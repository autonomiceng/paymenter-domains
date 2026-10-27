<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

function writeRenderArtifact(string $path, string $content, ?Closure $writer = null): void
{
    $completed = $GLOBALS['nativeSuccessful'] ?? false;
    $GLOBALS['nativeSuccessful'] = false;
    $writer ??= static fn(string $path, string $content): int|false => file_put_contents($path, $content);
    expect($writer($path, $content) === strlen($content), 'Unable to write render artifact: '.$path);
    $GLOBALS['nativeSuccessful'] = $completed;
}
