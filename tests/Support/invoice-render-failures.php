<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

function invoiceRenderFailures(): void
{
    global $nativeSuccessful;
    expect(function_exists('pcntl_fork'), 'Pinned CLI supports forked render checks');
    $failures = $exits = [];
    foreach ([null, 'lines.json', 'lines.html'] as $blocked) {
        $out = dirname((string) getenv('PAYMENTER_TEST_FIXTURE')).'/invoice-render-'.bin2hex(random_bytes(6));
        expect(mkdir($out, 0700), 'Create private render output');
        if ($blocked !== null) { expect(mkdir($out.'/'.$blocked), 'Block one output with a directory'); }
        $pid = pcntl_fork();
        if ($pid === 0) {
            $nativeSuccessful = true;
            set_error_handler(static fn (int $severity): bool => $severity === E_WARNING);
            ob_start(static fn (string $output): string => '');
            $argv = [dirname(__DIR__).'/invoice-lines-render.php', $out];
            require $argv[0];
            exit(0);
        }
        try {
            expect($pid !== -1, 'Fork invoice renderer');
            same($pid, pcntl_waitpid($pid, $status), 'Wait for invoice renderer');
            expect(pcntl_wifexited($status), 'Renderer exits normally rather than by signal');
            $exits[$blocked ?? 'writable'] = pcntl_wexitstatus($status);
            if ($blocked === null) {
                same(0, pcntl_wexitstatus($status), 'Writable invoice outputs succeed');
                expect(is_file($out.'/lines.json') && is_file($out.'/lines.html'), 'Both invoice files written');
                json_decode(file_get_contents($out.'/lines.json'), true, 512, JSON_THROW_ON_ERROR);
            } else {
                if (pcntl_wexitstatus($status) === 0) { $failures[] = $blocked; }
                expect(is_dir($out.'/'.$blocked), 'Failed write preserves output collision');
                if ($blocked === 'lines.html') { expect(is_file($out.'/lines.json'), 'JSON succeeded before HTML failure'); }
            }
        } finally {
            foreach (['lines.json', 'lines.html'] as $name) {
                if (is_dir($out.'/'.$name)) { rmdir($out.'/'.$name); }
                elseif (is_file($out.'/'.$name)) { unlink($out.'/'.$name); }
            }
            rmdir($out);
        }
    }
    echo 'Invoice render exits: '.json_encode($exits)."\n";
    same([], $failures, 'Write failures must exit nonzero: '.implode(', ', $failures));
}
