<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Every state and plain word has a dictionary entry, one word per case, and a tone for each reader.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Filament\Support\Contracts\{HasColor, HasLabel};
use Paymenter\Extensions\Servers\Domains\Presentation\{Entry, Plain, Reader, State, Tone};

$words = [];
foreach ([...State::cases(), ...Plain::cases()] as $case) {
    $name = $case::class.'::'.$case->name;
    $entry = Entry::of($case);
    expect($entry !== null, 'Exactly one dictionary entry: '.$name);
    expect($entry->nearest !== [] && !in_array($case, $entry->nearest, true), 'The entry names the nearest other words: '.$name);
    foreach ($entry->nearest as $near) { expect($near instanceof State || $near instanceof Plain, 'Nearest words are existing cases: '.$name); }
    expect(trim($entry->why) !== '' && trim($entry->decided) !== '', 'The entry says why no nearer word fits, and who decided: '.$name);
    $label = $case->label();
    expect(trim($label) !== '' && $case->getLabel() === $label, 'One label, the same through Filament: '.$name);
    $words[] = mb_strtolower($label);
}
same(count($words), count(array_unique($words)), 'One word, one case: no word is both a state and a plain word, or two states');

$byReader = [];
foreach (State::cases() as $state) {
    foreach (Reader::cases() as $reader) { expect($state->tone($reader) instanceof Tone, 'A tone for each reader: '.$state->name); }
    if ($state->tone(Reader::Staff) !== $state->tone(Reader::Customer)) { $byReader[$state->name] = [$state->tone(Reader::Staff), $state->tone(Reader::Customer)]; }
}
// Tone follows who must act (Ilya, Nov 12, 2026): only these three differ, and every other state reads one tone for both.
same(['Unpaid' => [Tone::Off, Tone::Attention], 'AwaitingPayment' => [Tone::Progress, Tone::Attention], 'UnderReview' => [Tone::Attention, Tone::Progress]], $byReader, 'Only the ruled states change tone by reader');
foreach (Tone::cases() as $tone) {
    expect(array_filter(State::cases(), static fn (State $s): bool => $s->tone(Reader::Staff) === $tone || $s->tone(Reader::Customer) === $tone) !== [], 'Every tone is used: '.$tone->name);
}
same(['ok', 'off', 'prog', 'warn', 'err'], array_map(static fn (Tone $t): string => $t->value, Tone::cases()), 'The five tones a skin draws from data-beco-tone');
// The shared block (results and the six availability states) is part of this enum.
foreach (['ok', 'failed', 'not_read_yet', 'checking', 'couldnt_read', 'not_set', 'not_for_sale', 'not_supported', 'not_recognized'] as $key) {
    expect(in_array(State::from($key), State::SHARED, true), 'Shared state: '.$key);
}
same(9, count(State::SHARED), 'The shared block holds results, availability and Not recognized only');
// A state is a label, never a Filament colour; Plain has no tone at all.
expect(is_subclass_of(State::class, HasLabel::class) && !is_subclass_of(State::class, HasColor::class), 'State implements HasLabel only');
expect(is_subclass_of(Plain::class, HasLabel::class) && !is_subclass_of(Plain::class, HasColor::class) && !method_exists(Plain::class, 'tone'), 'Plain has no tone or colour');
$nativeSuccessful = true; echo "Panel state coverage passed.\n";
