<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Plain, Reader, State, Tone};
use Paymenter\Extensions\Servers\Domains\Presentation\Codes\Coded;
/** One value on a panel: a State with its mark, a Plain word, or a Fact, then an optional sentence under it. Nothing
 * else: no bare text, colour, icon or class. A State's tone comes from its reader; staff may get one more line with
 * times and codes, which customers never see. Renders the domains::components.panel.value Blade component, which
 * emits neutral domains-panel-* classes and data-domains-tone, and reads as stacked text in plain Filament. */
final class Value implements Htmlable
{
    /** @param list<Fact> $extra Staff only. */
    private function __construct(public readonly string $kind, public readonly string $word, public readonly ?Tone $tone, public readonly ?Fact $fact,
        public readonly ?string $sub, public readonly ?Reader $reader, public readonly ?string $record, public readonly array $extra = [], public readonly ?State $state = null, public readonly ?Fact $subAt = null, public readonly ?\Illuminate\Support\HtmlString $tag = null, public readonly ?string $then = null)
    { assert($sub === null || trim($sub) !== '' && !Fact::isWord($sub), 'A line under a value is a sentence, never a state or plain word: '.$sub); }

    /** $record names the record the state belongs to, so a skin sweeps the mark only on a real change. */
    /** $at ends the reason with its time, as a retry reads: "Release the hold. Next try, <time>." */
    public static function state(State $state, Reader $reader, ?string $reason = null, ?string $record = null, ?Fact $at = null): self
    {
        if ($at !== null && $reason === null) { throw new \InvalidArgumentException('A time ends a sentence.'); }
        return new self('state', $state->label(), $state->tone($reader), null, $reason, $reader, $record, [], $state, $at);
    }
    /** A provider code's state. An unmapped code keeps the code on the staff line. */
    public static function coded(Coded $coded, Reader $reader, ?string $reason = null, ?string $record = null): self
    { $value = self::state($coded->state, $reader, $reason, $record); return $coded->code === null ? $value : $value->detail($coded->code); }
    /** $at ends the sentence with its time, as a finished change reads: "Turned off. Confirmed at the registrar, <time>." */
    public static function plain(Plain $plain, ?string $sub = null, ?Fact $at = null): self
    {
        if ($at !== null && $sub === null) { throw new \InvalidArgumentException('A time ends a sentence.'); }
        return new self('plain', $plain->label(), null, null, $sub, null, null, [], null, $at);
    }
    /** $at follows a short label under the fact, with no punctuation, as the mock's schedule reads: "Weekly, Sunday 03:00"
     * over "Next <time>". */
    public static function fact(Fact $fact, ?string $sub = null, ?Fact $at = null): self
    {
        if ($at !== null && $sub === null) { throw new \InvalidArgumentException('A time follows a label.'); }
        return new self('fact', $fact->text, null, $fact, $sub, null, null, [], null, $at);
    }
    /** A state's time, such as when a change was sent or a read last failed. Staff only, since a reason carries no time. */
    public function when(int|\DateTimeInterface $at): self { return $this->detail(Fact::date($at, Reader::Staff)); }
    /** Codes, provider names or times for staff, on the line under the reason. */
    public function detail(Fact ...$facts): self
    {
        if ($this->reader === null) { throw new \LogicException('Only a state has a staff line.'); }
        return new self($this->kind, $this->word, $this->tone, $this->fact, $this->sub, $this->reader, $this->record, [...$this->extra, ...array_values($facts)], $this->state);
    }
    /** True for Checking: we wait on the registrar, so a row holding it gets the waiting edge. */
    public function waits(): bool { return $this->state === State::Checking; }
    /** The staff line, empty for customers. @return list<Fact> */
    public function shownExtra(): array { return $this->reader === Reader::Staff ? $this->extra : []; }
    /** The Demo tag that belongs after the first line, beside the name it marks. */
    public function tagged(?\Illuminate\Support\HtmlString $tag): self
    { return $tag === null ? $this : new self($this->kind, $this->word, $this->tone, $this->fact, $this->sub, $this->reader, $this->record, $this->extra, $this->state, $this->subAt, $tag, $this->then); }
    /** One more sentence at the end of the line under the value, such as who sets a setting. */
    public function then(string $sentence): self
    {
        $sentence = trim($sentence);
        if ($sentence === '' || !str_ends_with($sentence, '.')) { throw new \InvalidArgumentException('The line under a value ends with a sentence.'); }
        return new self($this->kind, $this->word, $this->tone, $this->fact, $this->sub, $this->reader, $this->record, $this->extra, $this->state, $this->subAt, $this->tag, $sentence);
    }
    public function toHtml(): string { return view('domains::components.panel.value', ['value' => $this])->render(); }
}
