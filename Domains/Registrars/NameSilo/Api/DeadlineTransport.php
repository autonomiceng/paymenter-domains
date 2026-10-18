<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

/** Read transport with the engine's remaining deadline, never a purchase interface. */
interface DeadlineTransport extends Transport
{
    public function sendWithin(Environment $environment, RequestContext $context, RegistrarRequest $request,
        #[\SensitiveParameter] string $apiKey, int $timeoutMs): TransportResponse;
}
