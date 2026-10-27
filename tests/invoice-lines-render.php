<?php
// Synthetic wording examples using the runtime formatter; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/native-app.php';
require __DIR__.'/Support/invoice-descriptions.php';
$out = $argv[1] ?? '';
expect(is_dir($out), 'Existing output directory required');
writeRenderArtifact($out.'/lines.json', json_encode($invoiceLineExamples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
$rows = '';
foreach ($invoiceLineExamples as $key => $line) {
    $rows .= '<tr><td>'.htmlspecialchars($key).'</td><td>'.htmlspecialchars($line).'</td><td>$12.00</td></tr>';
}
$html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Invoice wording examples</title><style>
:root{color-scheme:light dark;font:16px/1.55 system-ui;background:#f8fafc;color:#172033}body{margin:40px auto;max-width:1160px;padding:0 32px}h1{font-size:28px}p{color:#64748b}table{border-collapse:collapse;width:100%;background:#fff}td,th{padding:16px;border-bottom:1px solid #cbd5e1;text-align:left}td:nth-child(2){overflow-wrap:anywhere}td:first-child{font-size:13px;color:#64748b;width:145px}td:last-child{white-space:nowrap}th{font-size:13px} @media(prefers-color-scheme:dark){:root{background:#111827;color:#e2e8f0}table{background:#182234}td,th{border-color:#334155}p,td:first-child{color:#94a3b8}}
</style><h1>Invoice wording examples</h1><p>Synthetic formatter output for wording review. Amounts are illustrative. This is a review sheet, not an installed invoice page.</p><table><thead><tr><th>Case</th><th>Description</th><th>Amount</th></tr></thead><tbody>'.$rows.'</tbody></table></html>';
writeRenderArtifact($out.'/lines.html', $html);
$nativeSuccessful = true;
echo "Invoice wording review examples rendered.\n";
