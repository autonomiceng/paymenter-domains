<?php
// Synthetic structural fixtures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{RegistrarRequest as Request, Outcome};
$observed = static function (string $command, array $reply) use ($parser) {
    return $parser->parse(json_encode(['request' => ['operation' => $command],
        'reply' => ['code' => 300, 'detail' => 'synthetic'] + $reply], JSON_THROW_ON_ERROR),
        match ($command) { 'getAccountBalance' => Request::balance(), 'getPrices' => Request::prices(),
            default => Request::availability(['example.com'], false) }, 'synthetic-key');
};
// Grouping is lexical evidence, never a permissive strip of arbitrary punctuation.
same('12345.67', $observed('getAccountBalance', ['balance' => '12,345.67'])->value('balance'), 'Observed grouped balance exact');
$rates = ['registration' => '1,234.56', 'transfer' => '2,345.67', 'renew' => '3,456.78'];
same('1234.56', $observed('getPrices', ['com' => $rates])->catalogueRows()['com']['registration'] ?? null, 'Whole grouped catalogue exact');
foreach (['12,34.56', '1234,567.89', '1,,234.56', ',123.45', '1,234.567', '0,123.45', '000,001.00', '1,234', '1,234.5', ' 1,234.56', '-1,234.56', '', null, false] as $bad) {
    same(Outcome::Unknown, $observed('getAccountBalance', ['balance' => $bad])->outcome, 'Malformed grouped balance rejected');
    $partial = $observed('getPrices', ['com' => $rates, 'net' => array_replace($rates, ['renew' => $bad])]);
    same(Outcome::Success, $partial->outcome, 'Valid row survives malformed neighbour');
    same(null, $partial->catalogueRows()['net'], 'Malformed catalogue row quarantines every action');
    same('1234.56', $partial->catalogueRows()['com']['registration'], 'Valid neighbour retains exact money');
}
$available = ['domain' => 'example.com', 'price' => '7.12345678901234567', 'premium' => 0, 'duration' => 1, 'renew' => '9.98765432109876543'];
$result = $observed('checkRegisterAvailability', ['available' => [$available]]);
same(Outcome::Success, $result->outcome, 'Observed exact long fractional availability accepted');
same($available['price'], $result->value('available')[0]['price'], 'Availability fraction never rounded');
expect(!array_key_exists('renew', $result->value('available')[0]), 'Renew ignored explicitly; not a quote');
$numeric = '{"request":{"operation":"checkRegisterAvailability"},"reply":{"code":300,"detail":"synthetic","available":[{"domain":"example.com","price":7.12345678901234567,"premium":0,"duration":1,"renew":9.98765432109876543}]}}';
same($available['price'], $parser->parse($numeric, Request::availability(['example.com'], false), 'synthetic-key')->value('available')[0]['price'], 'Raw numeric fractional lexeme exact');
foreach (['1e2', '7.', '.7', '-7.01', '7,123.456789', '', null, false, []] as $bad) {
    same(Outcome::Unknown, $observed('checkRegisterAvailability', ['available' => [array_replace($available, ['price' => $bad])]])->outcome, 'Malformed long fractional price rejected');
}
foreach ([null, [], 'ignored-metadata'] as $ignored) {
    $result = $observed('checkRegisterAvailability', ['available' => [array_replace($available, ['renew' => $ignored])]]);
    same(Outcome::Success, $result->outcome, 'Renew metadata never supplies or invalidates price evidence');
    expect(!array_key_exists('renew', $result->value('available')[0]), 'Ignored renew never exposed');
}
$missingPrice = $available; unset($missingPrice['price']);
same(Outcome::Unknown, $observed('checkRegisterAvailability', ['available' => [$missingPrice]])->outcome, 'Renew cannot replace absent price');
same(Outcome::Unknown, $observed('getAccountBalance', ['balance' => '7.12345678901234567'])->outcome, 'Financial amount precision unchanged');
