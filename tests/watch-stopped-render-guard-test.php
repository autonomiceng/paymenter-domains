<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The stopped-monitoring render fixture refuses outside the skin harness, before any setup or page write.
putenv('DOMAINS_PANEL_SETUP');putenv('SKIN_RENDER_MARKER');
$out=sys_get_temp_dir().'/watch-stopped-render-guard-'.bin2hex(random_bytes(4));mkdir($out);$argv=[__FILE__,$out];
$refused=false;
try { require __DIR__.'/Support/watch-stopped-render.php'; } catch (RuntimeException) { $refused=true; }
$written=array_values(array_diff(scandir($out),['.','..']));rmdir($out);
expect($refused,'A direct run of the render fixture is refused');
expect(!function_exists('noticeFixture'),'The refusal comes before any fixture setup');
expect($written===[],'A direct run of the render fixture writes no page');
echo "The stopped-monitoring render fixture refuses outside the skin harness.\n";
