<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    $prefix = 'Paymenter\\Extensions\\Servers\\Domains\\';
    if (str_starts_with($name, $prefix)) {
        require dirname(__DIR__).'/Domains/'.str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
    }
});

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    expect($expected === $actual, $message);
}

/** Synthetic fixtures explicitly accept processing without installing a provider runtime. */
function syntheticActivation(): Closure
{
    return static fn () => null;
}
