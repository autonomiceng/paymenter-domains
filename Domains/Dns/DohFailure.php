<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Safe transport categories only; never expose remote bodies or exception messages. */
final class DohFailure extends \RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        if (!in_array($kind, ['timeout','connect','tls','http_status','too_large','malformed','unavailable'], true)) { throw new \InvalidArgumentException('Invalid transport failure.'); }
        parent::__construct('DNS-over-HTTPS '.$kind);
    }
}
