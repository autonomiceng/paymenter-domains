<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

/** A filesystem boundary that can stop after accepting only part of a write. */
final class ArtifactWriteStream
{
    public mixed $context;
    public static array $writes = [];
    public static string $bytes = '';
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $data): int|false
    {
        $count = array_shift(self::$writes) ?? 0;
        if ($count !== false) { self::$bytes .= substr($data, 0, $count); }
        return $count;
    }
    public function stream_close(): void {}
}
expect(stream_wrapper_register('artifactwrite', ArtifactWriteStream::class), 'Controlled filesystem stream registered');
$path = 'artifactwrite://output';
$content = "é雪";
same(5, strlen($content), 'Artifact limit uses bytes, not characters');
// PHP retries a short stream write, then reports false when the stream stops.
ArtifactWriteStream::$writes = [2, 0]; ArtifactWriteStream::$bytes = '';
same(false, @file_put_contents($path, $content), 'Native short stream reports failure');
same("é", ArtifactWriteStream::$bytes, 'Native stream actually retained two of five bytes');
ArtifactWriteStream::$writes = [2, 0]; ArtifactWriteStream::$bytes = '';
$GLOBALS['nativeSuccessful'] = true; $failed = false;
try { @writeRenderArtifact($path, $content); } catch (RuntimeException) { $failed = true; }
expect($failed, 'Native incomplete stream refuses the artifact');
same(false, $GLOBALS['nativeSuccessful'], 'Native short write leaves completion false');

// Exercise all filesystem count outcomes, including writers reporting partial progress.
foreach (['partial' => 2, 'zero' => 0, 'false' => false] as $case => $written) {
    foreach ([true, false] as $previous) {
        $calls = []; $GLOBALS['nativeSuccessful'] = $previous; $failure = null;
        $writer = static function(string $target, string $bytes) use ($written, &$calls): int|false {
            $calls[] = [$target, $bytes]; return $written;
        };
        try { writeRenderArtifact($path, $content, $writer); } catch (RuntimeException $error) { $failure = $error; }
        same([[$path, $content]], $calls, 'Filesystem writer receives exact path and bytes');
        expect($failure instanceof RuntimeException, 'Incomplete artifact refuses '.$case);
        same('Unable to write render artifact: '.$path, $failure->getMessage(), 'Write failure names the artifact');
        same(false, $GLOBALS['nativeSuccessful'], 'Incomplete artifact clears the success marker for '.$case);
    }
}
foreach (['', $content] as $complete) {
    foreach ([true, false] as $previous) {
        ArtifactWriteStream::$writes = [strlen($complete)]; ArtifactWriteStream::$bytes = '';
        same(strlen($complete), file_put_contents($path, $complete), 'Complete filesystem count includes empty artifacts');
        ArtifactWriteStream::$writes = [strlen($complete)]; ArtifactWriteStream::$bytes = '';
        $GLOBALS['nativeSuccessful'] = $previous;
        writeRenderArtifact($path, $complete);
        same($complete, ArtifactWriteStream::$bytes, 'Complete artifact bytes retained');
        same($previous, $GLOBALS['nativeSuccessful'], 'Complete artifact restores the prior marker');
    }
}
expect(stream_wrapper_unregister('artifactwrite'), 'Controlled filesystem stream removed');
echo "Artifact byte counts and completion markers passed.\n";
