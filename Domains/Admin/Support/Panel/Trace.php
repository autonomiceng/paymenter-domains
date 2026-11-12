<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel;
use Filament\Schemas\Components\Component;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
/** Things that happened in order (activity, checks, setup steps): one thin line with a rail-size mark per step. Each
 * step is a State with its word, the event's own label, when, who, and an optional reason. The mark and word tone
 * come from the State for this reader; staff see the time, customers the date. Emits an ordered list with
 * data-domains-tone, so without a skin it reads as a plain numbered list. */
final class Trace extends Component implements HasEmbeddedView
{
    /** @var list<array{label:string,state:State,at:?Fact,by:?string,reason:?string,record:?string}> */
    private array $steps = [];
    final public function __construct(private Reader $reader) {}
    public static function make(Reader $reader): self
    {
        $trace = app(self::class, ['reader' => $reader]);
        $trace->configure();
        return $trace;
    }
    /** $label is the event as history names it ("Renewal sent to Enom TEST"). $by is who did it ("the customer",
     * "Ilya"). $reason is a sentence with no time. $record names the step, so a skin sweeps it only on a real change. */
    public function step(string $label, State $state, int|\DateTimeInterface|null $at = null, ?string $by = null, ?string $reason = null, ?string $record = null): self
    {
        if (trim($label) === '') { throw new \InvalidArgumentException('A step needs its label.'); }
        if ($reason !== null && (trim($reason) === '' || Fact::isWord($reason))) { throw new \InvalidArgumentException('A reason is a sentence, never a state or plain word.'); }
        $this->steps[] = ['label' => $label, 'state' => $state, 'at' => $at === null ? null : Fact::date($at, $this->reader),
            'by' => $by === null || trim($by) === '' ? null : trim($by), 'reason' => $reason, 'record' => $record];
        return $this;
    }
    public function toEmbeddedHtml(): string
    {
        return view('domains::components.panel.trace', ['steps' => array_map(fn (array $step): array => [...$step,
            'tone' => $step['state']->tone($this->reader), 'word' => $step['state']->label()], $this->steps)])->render();
    }
}
