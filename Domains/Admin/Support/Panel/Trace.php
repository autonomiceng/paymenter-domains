<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel;
use Filament\Actions\Action;
use Filament\Schemas\Components\{Actions, Component};
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
/** Things that happened in order (activity, checks, setup steps): one thin line with a rail-size mark per step. Each
 * step is a State with its word, the event's own label, when, who, and an optional reason. The mark and word tone
 * come from the State for this reader; staff see the time, customers the date. An event with no state of its own (a
 * note, a request) passes null and draws no mark and no word, never a borrowed Completed. Emits an ordered list with
 * data-domains-tone, so without a skin it reads as a plain numbered list. A step may carry one native action or link
 * (a check's fix, the list it opens); the page registers it as always and the step only places it. */
final class Trace extends Component implements HasEmbeddedView
{
    /** @var list<array{label:string,state:?State,at:?Fact,by:?string,reason:?string,record:?string,action:?Action}> */
    private array $steps = [];
    final public function __construct(private Reader $reader) {}
    public static function make(Reader $reader): self
    {
        $trace = app(self::class, ['reader' => $reader]);
        $trace->configure();
        return $trace;
    }
    /** $label is the event as history names it ("Renewal sent to Enom TEST"). $state is null for an event with no
     * state of its own. $by is who did it ("the customer",
     * "Ilya"). $reason is a sentence with no time. $record names the step, so a skin sweeps it only on a real change. $action is
     * the step's one action or link. */
    public function step(string $label, ?State $state, int|\DateTimeInterface|null $at = null, ?string $by = null, ?string $reason = null, ?string $record = null, ?Action $action = null): self
    {
        if (trim($label) === '') { throw new \InvalidArgumentException('A step needs its label.'); }
        if ($reason !== null && (trim($reason) === '' || Fact::isWord($reason))) { throw new \InvalidArgumentException('A reason is a sentence, never a state or plain word.'); }
        $this->steps[] = ['label' => $label, 'state' => $state, 'at' => $at === null ? null : Fact::date($at, $this->reader),
            'by' => $by === null || trim($by) === '' ? null : trim($by), 'reason' => $reason, 'record' => $record, 'action' => $action];
        $acts = [];
        foreach ($this->steps as $i => $step) { if ($step['action'] !== null) { $acts[] = Actions::make([$step['action']])->key('step-'.$i.'-actions'); } }
        return $acts === [] ? $this : $this->schema($acts);
    }
    public function toEmbeddedHtml(): string
    {
        $placed = [];
        $any = array_filter($this->steps, static fn (array $s): bool => $s['action'] !== null) !== [];
        foreach ($any ? $this->getChildSchema()->getComponents() : [] as $c) { $placed[(string) $c->getKey(false)] = $c; }
        return view('domains::components.panel.trace', ['steps' => array_map(fn (array $step, int $i): array => [...$step,
            'tone' => $step['state']?->tone($this->reader) ?? \Paymenter\Extensions\Servers\Domains\Presentation\Tone::Off, 'word' => $step['state']?->label(),
            'action' => $step['action'] !== null && $step['action']->isVisible() && isset($placed['step-'.$i.'-actions']) ? $placed['step-'.$i.'-actions']->toHtml() : null],
            $this->steps, array_keys($this->steps))])->render();
    }
}
