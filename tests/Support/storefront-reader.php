<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real read adapter, synthetic transport only.
function storefrontTransferReader(object $state): Paymenter\Extensions\Servers\Domains\Services\AccountBindings
{
    $registry = new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
    $verifier = new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
        public function verify($environment, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
    };
    $wire = new class($state) implements Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport {
        public function __construct(private object $state) {}
        public function send($environment, $command, #[SensitiveParameter] array $parameters,
            #[SensitiveParameter] string $uid, #[SensitiveParameter] string $token, int $timeoutMs): string
        {
            $this->state->calls++;
            return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><Domains><Domain><Name>transfer-placeholder.com</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Properties><Transferable>true</Transferable></Properties></Domain></Domains></interface-response>';
        }
    };
    $registry->register('enom', ['token'], $verifier, static function ($binding, $credentials) use ($wire, $state) {
        if ($state->fail) { throw new RuntimeException('Enom account-private-key vendor diagnostic'); }
        return new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(
            new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($binding, $credentials['token'], 'synthetic', $wire), ['com']);
    });
    return new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
}
