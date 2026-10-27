<?php
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/storefront.php';
require dirname(__DIR__,2).'/Domains/routes/web.php';
storefrontViews();
$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
$html=view('domains::search',storefrontData())->render();
// Minimal offline shell: real Blade and stylesheet, without theme assets or scripts.
$base='html{font-size:16px}*{box-sizing:border-box}body{margin:0;padding:16px;font-family:system-ui}button{font-family:inherit}';
writeRenderArtifact($out.'/button-transfer.html',str_replace('</head>','<style>'.$base.'</style></head>',$html));
writeRenderArtifact($out.'/button-layout-source.json',json_encode(['stylesheet_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/Domains/resources/views/partials/styles.blade.php')]));
$nativeSuccessful=true;echo "Real Blade Transfer search rendered for the explicit browser check.\n";
