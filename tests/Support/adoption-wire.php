<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, Transport};

final class AdoptionClock implements Clock
{
    public DateTimeImmutable $time;
    public function __construct() { $this->time = new DateTimeImmutable('2026-10-19T00:00:00+00:00'); }
    public function now(): DateTimeImmutable { return $this->time; }
}
final class AdoptionWire implements Transport
{
    public array $calls = [];
    public array $payloads;
    public ?Closure $during = null;
    public function __construct()
    {
        $this->payloads = [
            'GetDomainInfo' => '<GetDomainInfo><DomainName DomainNameID="123" SLD="example" TLD="com">example.com</DomainName><Status><belongs-to>synthetic-uid</belongs-to><expiration>10/19/2027 11:14:00 PM</expiration></Status></GetDomainInfo>',
            'GetDomainExp' => '<ExpirationDate>10/19/2027 11:14:00 PM</ExpirationDate>',
            'GetRenew' => '<auto-renew>false</auto-renew>',
            'GetExtendInfo' => '<Expiration>10/19/2027</Expiration><RegistrarHold>false</RegistrarHold><MaxExtension>9</MaxExtension>',
        ];
    }
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $this->calls[] = [$command->value, $parameters, $environment->value, $timeoutMs];
        if ($this->during !== null) { ($this->during)($command); }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'
            .($this->payloads[$command->value] ?? '').'</interface-response>';
    }
}
