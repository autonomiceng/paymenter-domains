<?php
// Synthetic OTE singleton availability; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{RegistrarRequest as SingletonRequest, Outcome as SingletonOutcome};
$singleton = static function (array $reply, array $names = ['example.com'], bool $transfer = false) use ($parser) {
    return $parser->parse(json_encode(['request' => ['operation' => $transfer ? 'checkTransferAvailability' : 'checkRegisterAvailability'],
        'reply' => ['code' => 300, 'detail' => 'synthetic'] + $reply], JSON_THROW_ON_ERROR),
        SingletonRequest::availability($names, $transfer), 'synthetic-key');
};
$singletonRow = ['domain' => 'example.com', 'price' => 8.95, 'premium' => 0, 'duration' => 1, 'renew' => 9.95];
$wrap = static fn ($row) => ['available' => ['domain' => $row]];
$accepted = $singleton($wrap($singletonRow));
same(SingletonOutcome::Success, $accepted->outcome, 'Singleton wrapper accepted');
same([['domain' => 'example.com', 'price' => '8.95', 'premium' => false, 'duration' => '1']], $accepted->value('available'), 'Singleton preserves validated evidence');
$upper = $singleton($wrap(array_replace($singletonRow, ['domain' => 'EXAMPLE.COM'])));
same('example.com', $upper->value('available')[0]['domain'] ?? null, 'Singleton case-normalized exact identity');
same(SingletonOutcome::Unknown, $singleton($wrap(array_replace($singletonRow, ['domain' => 'other.com'])))->outcome, 'Singleton wrong name unknown');
// Covering the other name separately must not make a multi-name nested wrapper acceptable.
same(SingletonOutcome::Unknown, $singleton($wrap($singletonRow) + ['unavailable' => [['domain' => 'example.net']]],
    ['example.com', 'example.net'])->outcome, 'Singleton wrapper with two requested names unknown');
foreach (['price', 'premium'] as $field) {
    foreach ([false, [], new stdClass, 'mistyped'] as $bad) {
        same(SingletonOutcome::Unknown, $singleton($wrap(array_replace($singletonRow, [$field => $bad])))->outcome, 'Singleton mistyped '.$field.' unknown');
    }
}
$withoutPrice = $singletonRow; unset($withoutPrice['price']);
same(SingletonOutcome::Unknown, $singleton($wrap($withoutPrice))->outcome, 'Singleton missing price unknown');
// Preserve list semantics: missing premium is unknown eligibility, never standard-name proof.
$withoutPremium = $singletonRow; unset($withoutPremium['premium']);
$unknownPremium = $singleton($wrap($withoutPremium));
same(SingletonOutcome::Success, $unknownPremium->outcome, 'Singleton missing premium preserves parsed row');
same(null, $unknownPremium->value('available')[0]['premium'] ?? null, 'Singleton missing premium never false');
same(true, $singleton($wrap(array_replace($singletonRow, ['premium' => 1])))->value('available')[0]['premium'] ?? null, 'Singleton premium one retained');
same(SingletonOutcome::Unknown, $singleton($wrap(new stdClass))->outcome, 'Singleton empty object unknown');
same(SingletonOutcome::Unknown, $singleton(['available' => new stdClass])->outcome, 'Empty available object unknown');
same(SingletonOutcome::Unknown, $singleton($wrap($singletonRow) + ['unavailable' => [['domain' => 'example.com']]])->outcome, 'Singleton duplicate correlation unknown');
same(SingletonOutcome::Unknown, $singleton(['available' => ['domain' => $singletonRow, 'extra' => true]])->outcome, 'Singleton extra wrapper keys unknown');
same(SingletonOutcome::Unknown, $singleton($wrap($singletonRow), ['example.com'], true)->outcome, 'Transfer nested wrapper remains unknown');
foreach (['unavailable', 'invalid'] as $sibling) {
    same(SingletonOutcome::Success, $singleton([$sibling => ['domain' => 'example.com']])->outcome, 'Documented direct sibling retained');
    same(SingletonOutcome::Unknown, $singleton([$sibling => ['domain' => $singletonRow]])->outcome, 'Undocumented nested sibling unknown');
}
// Duplicate names differing only in case cannot evade exact single-occurrence correlation.
same(SingletonOutcome::Unknown, $singleton(['available' => [$singletonRow, array_replace($singletonRow, ['domain' => 'EXAMPLE.COM'])]])->outcome, 'Case-normalized duplicate unknown');
