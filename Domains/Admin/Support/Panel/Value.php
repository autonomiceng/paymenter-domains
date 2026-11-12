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
 * emits neutral beco-* classes and data-beco-tone, and reads as stacked text in plain Filament. */
final class Value implements Htmlable
{
    /** @param list<Fact> $extra Staff only. */
    private function __construct(public readonly string $kind, public readonly string $word, public readonly ?Tone $tone, public readonly ?Fact $fact,
        public readonly ?string $sub, public readonly ?Reader $reader, public readonly ?string $record, public readonly array $extra = [])
    { assert($sub === null || trim($sub) !== '' && !Fact::isWord($sub), 'A line under a value is a sentence, never a state or plain word: '.$sub); }

    /** $record names the record the state belongs to, so a skin sweeps the mark only on a real change. */
    public static function state(State $state, Reader $reader, ?string $reason = null, ?string $record = null): self
    { return new self('state', $state->label(), $state->tone($reader), null, $reason, $reader, $record); }
    /** A provider code's state. An unmapped code keeps the code on the staff line. */
    public static function coded(Coded $coded, Reader $reader, ?string $reason = null, ?string $record = null): self
    { $value = self::state($coded->state, $reader, $reason, $record); return $coded->code === null ? $value : $value->detail($coded->code); }
    public static function plain(Plain $plain, ?string $sub = null): self { return new self('plain', $plain->label(), null, null, $sub, null, null); }
    public static function fact(Fact $fact, ?string $sub = null): self { return new self('fact', $fact->text, null, $fact, $sub, null, null); }
    /** A state's time, such as when a change was sent or a read last failed. Staff only, since a reason carries no time. */
    public function when(int|\DateTimeInterface $at): self { return $this->detail(Fact::date($at, Reader::Staff)); }
    /** Codes, provider names or times for staff, on the line under the reason. */
    public function detail(Fact ...$facts): self
    {
        if ($this->reader === null) { throw new \LogicException('Only a state has a staff line.'); }
        return new self($this->kind, $this->word, $this->tone, $this->fact, $this->sub, $this->reader, $this->record, [...$this->extra, ...array_values($facts)]);
    }
    /** The staff line, empty for customers. @return list<Fact> */
    public function shownExtra(): array { return $this->reader === Reader::Staff ? $this->extra : []; }
    public function toHtml(): string { return view('domains::components.panel.value', ['value' => $this])->render(); }
}
