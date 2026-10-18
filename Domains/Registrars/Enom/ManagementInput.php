<?php
// Enom 444f31c limits; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPayload;
final class ManagementInput extends ManagementPayload
{
    public static function validate(string $action,#[\SensitiveParameter] array $p,string $name): array
    {
        $clean=parent::normalize($action,$p,$name,'enom');
        if($action==='nameservers'&&count($clean['nameservers'])>12){throw new \DomainException('Enom nameserver limit.');}return $clean;
    }
}
