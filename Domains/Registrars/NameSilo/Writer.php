<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\{RegistrationBridge, WriteAcceptance, WriteFeatures};
final class Writer extends RegistrationBridge
{
    public function __construct(AccountBinding $binding, WriteFeatures $features, WriteAcceptance $accepted,
        \Closure $engine, #[\SensitiveParameter] RegistrationTransport $transport)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('Wrong registrar writer.'); }
        parent::__construct($binding, $features, $accepted, $engine, $transport);
    }
}
