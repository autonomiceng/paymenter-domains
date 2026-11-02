<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** The directory asked for, or a temporary one that goes when the suite run ends. */
function renderOutput(?string $dir): string
{
    if ($dir !== null) { expect(is_dir($dir) && is_writable($dir), 'Output directory required'); return $dir; }
    $dir = sys_get_temp_dir().'/domains-render-'.getmypid();expect(mkdir($dir), 'Unable to create render directory');
    register_shutdown_function(static fn() => (new Illuminate\Filesystem\Filesystem())->deleteDirectory($dir));
    return $dir;
}

function writeRenderArtifact(string $path, string $content, ?Closure $writer = null): void
{
    $completed = $GLOBALS['nativeSuccessful'] ?? false;
    $GLOBALS['nativeSuccessful'] = false;
    $writer ??= static fn(string $path, string $content): int|false => file_put_contents($path, $content);
    expect($writer($path, $content) === strlen($content), 'Unable to write render artifact: '.$path);
    $GLOBALS['nativeSuccessful'] = $completed;
}
