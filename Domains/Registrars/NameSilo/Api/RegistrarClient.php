<?php
// Adapted from Enom Api/RegistrarClient.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class RegistrarClient implements \JsonSerializable
{
    private readonly \SensitiveParameterValue $credential;
    private readonly \SensitiveParameterValue $transport;

    public function __construct(#[\SensitiveParameter] string $apiKey, private readonly Environment $environment,
        #[\SensitiveParameter] Transport $transport)
    {
        if ($apiKey === '' || strlen($apiKey) > 256 || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new \InvalidArgumentException('Invalid registrar credential.');
        }
        $this->credential = new \SensitiveParameterValue($apiKey);
        $this->transport = new \SensitiveParameterValue($transport);
    }

    public function execute(RegistrarRequest $request, RequestContext $context): RegistrarResult
    {
        try {
            $key = $this->credential->getValue();
            $response = $this->transport->getValue()->send($this->environment, $context, $request, $key);
            if ($response->httpStatus !== 200 || strlen($response->body()) > CurlTransport::BODY_LIMIT) {
                return new RegistrarResult(Outcome::Unknown);
            }
            return (new ResponseParser)->parse($response->body(), $request, $key);
        } catch (\Throwable) {
            // Do not retain/chain errors, raw response, URL or transport diagnostic text.
            return new RegistrarResult(Outcome::Unknown);
        }
    }

    public function jsonSerialize(): array { return ['environment' => $this->environment->value]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { return $this->jsonSerialize(); }
    public function __unserialize(array $data): void { throw new \LogicException('Clients cannot be restored.'); }
    public function __toString(): string { return 'NameSilo '.$this->environment->value; }
}
