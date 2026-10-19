<?php
// Small synthetic list only; no downloaded data. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
function pslFixture(int $icann = 40, int $private = 40): string
{
    $a = ['com', 'co.uk', '*.ck', '!www.ck', '公司.cn']; $b = ['github.io'];
    for ($i = count($a); $i < $icann; $i++) { $a[] = 'i'.$i.'.example'; }
    for ($i = count($b); $i < $private; $i++) { $b[] = 'p'.$i.'.example'; }
    return "// synthetic MPL-style parser fixture\n// ===BEGIN ICANN DOMAINS===\n".implode("\n", $a).
        "\n// ===END ICANN DOMAINS===\n// ===BEGIN PRIVATE DOMAINS===\n".implode("\n", $b)."\n// ===END PRIVATE DOMAINS===\n";
}
final class PslClock implements Clock
{
    public function __construct(public int $at = 1800000000) {}
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@'.$this->at); }
}
