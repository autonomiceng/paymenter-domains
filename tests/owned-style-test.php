<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Owned pages use the shared pieces and tokens. New inline styles and raw colors fail here. A file on this list drops off as its page lands.
declare(strict_types=1);
const ALLOWED=[
    // Existing main pages leave this list when their later sheet/customer slice lands.
    'resources/views/admin/native-editor-notice.blade.php'=>2,
    'resources/views/admin/what-you-sell.blade.php'=>4,
    'resources/views/partials/demo-tag-colors.blade.php'=>4,
    'resources/views/partials/demo-tag.blade.php'=>1,
    'resources/views/search.blade.php'=>1,
    'resources/views/service.blade.php'=>2,
];
/** The stylesheets hold the tokens and email clients need inline styles, so neither is a page. */
const SKIPPED='~/partials/(staff-|domain-name-)?styles\.blade\.php$|/emails?/|WatchNoticeTemplates\.php$~';
$root=dirname(__DIR__).'/Domains';$found=[];
foreach (['Admin','resources/views'] as $area) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$area",FilesystemIterator::SKIP_DOTS)) as $file) {
        $path=substr($file->getPathname(),strlen($root)+1);
        if (!preg_match('/\.php$/',$path)||preg_match(SKIPPED,$path)) { continue; }
        $hits=preg_match_all('/\bstyle=|[\'"]style[\'"]\s*=>|<style\b|(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|\brgba?\(/',file_get_contents($file->getPathname()));
        if ($hits>0) { $found[$path]=$hits; }
    }
}
ksort($found);
foreach ($found as $path=>$hits) {
    if ($hits>(ALLOWED[$path]??0)) { throw new RuntimeException("$path has $hits inline styles or raw colors, allowed ".(ALLOWED[$path]??0).'. Use the shared pieces.'); }
}
foreach (ALLOWED as $path=>$allowed) {
    if (($found[$path]??0)<$allowed) { throw new RuntimeException("$path is down to ".($found[$path]??0).". Lower its number here."); }
}
