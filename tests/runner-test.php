<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
same('8.4.26', PHP_VERSION, 'Pinned PHP version');
expect(extension_loaded('curl'), 'cURL extension must load for transport option tests');
same('0', ini_get('allow_url_fopen'), 'URL streams disabled');
same('1', ini_get('zend.exception_ignore_args'), 'Exception arguments hidden');
foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client', 'exec', 'proc_open'] as $function) {
    expect(!function_exists($function), 'Network/process function must be disabled');
}
echo "Runner: pinned PHP and offline guards passed.\n";
