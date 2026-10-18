<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class RegistrarRequest implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $payload;

    private function __construct(public readonly string $command, #[\SensitiveParameter] array $parameters)
    {
        // Opaque PHP storage keeps contact/auth values out of ordinary dumpers and casts.
        $this->payload = new \SensitiveParameterValue($parameters);
    }

    public static function availability(array $domains, bool $transfer): self
    {
        if (!$domains || count($domains) > 200 || !array_is_list($domains)) {
            throw new \InvalidArgumentException('Supply between 1 and 200 domains.');
        }
        $names = [];
        foreach ($domains as $domain) {
            if (!is_string($domain)) { throw new \InvalidArgumentException('Invalid domain input.'); }
            $names[] = \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::normalize($domain);
        }
        return new self($transfer ? 'checkTransferAvailability' : 'checkRegisterAvailability',
            ['domains' => implode(',', array_unique($names))]);
    }

    public static function prices(?int $registrationDomains = null): self
    {
        if ($registrationDomains !== null && ($registrationDomains < 1 || $registrationDomains > 1000000)) {
            throw new \InvalidArgumentException('Invalid registration quantity.');
        }
        return new self('getPrices', $registrationDomains === null ? [] : ['registration_domains' => $registrationDomains]);
    }

    public static function domainInfo(string $domain): self
    {
        return new self('getDomainInfo', ['domain' => \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::normalize($domain)]);
    }

    public static function transferStatus(string $domain): self
    { return new self('checkTransferStatus', ['domain' => \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::normalize($domain)]); }

    public static function balance(): self { return new self('getAccountBalance', []); }

    public static function listOrders(?string $from = null, ?string $to = null): self
    {
        $parameters = [];
        foreach (['date_from' => $from, 'date_to' => $to] as $key => $date) {
            if ($date === null) { continue; }
            $format = strlen($date) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $date, new \DateTimeZone('UTC'));
            if (!$parsed || $parsed->format($format) !== $date) {
                throw new \InvalidArgumentException('Invalid order date filter.');
            }
            $parameters[$key] = $date;
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('Order dates must be ascending.');
        }
        return new self('listOrders', $parameters);
    }

    public static function orderDetails(string $orderNumber): self
    {
        self::id($orderNumber);
        return new self('orderDetails', ['order_number' => $orderNumber]);
    }

    private static function id(#[\SensitiveParameter] string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { throw new \InvalidArgumentException('Invalid provider identifier.'); }
    }

    /** Sensitive wire mapping; never log or persist this array. */
    public function parameters(): array { return $this->payload->getValue(); }
    public function jsonSerialize(): array { return ['command' => $this->command]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('Requests cannot be restored.'); }
    public function __toString(): string { return $this->command; }
}
