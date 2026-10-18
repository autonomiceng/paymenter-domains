<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

interface Transport
{
    /** One attempt only. Implementations must not log URLs, keys or response bodies. */
    public function send(Environment $environment, RequestContext $context, RegistrarRequest $request,
        #[\SensitiveParameter] string $apiKey): TransportResponse;
}
