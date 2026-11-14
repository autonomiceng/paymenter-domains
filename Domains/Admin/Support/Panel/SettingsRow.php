<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Paymenter\Extensions\Servers\Domains\Presentation\Plain;
/** A setting: the same row as Row, for values someone chooses. It always knows who sets it, so a reader with no
 * control here (no permission, or someone else's setting) sees the value with who sets it on the line under it.
 * A switch is the page's own native action drawn as a toggle, right before the On or Off it sets (Ilya, Nov 14, 2026),
 * so the word and the switch read together and can't disagree. A second control stays in the action column. */
final class SettingsRow extends Row
{
    protected string $kind = 'setting';
    private ?string $setBy = null;
    private ?bool $on = null;

    /** $setBy is one sentence: "Set by the customer on their payment page." */
    public static function setting(string $label, Value $value, string $setBy): self
    {
        $row = self::make($label, $value);
        $row->setBy = self::sentence($setBy);
        return $row;
    }
    /** An on or off setting that takes effect in place. $switch is the page's action that flips it. */
    public static function toggle(string $label, bool $on, Action $switch, string $setBy, ?string $sub = null): self
    {
        $row = self::setting($label, Value::plain($on ? Plain::On : Plain::Off, $sub), $setBy);
        $row->on = $on;
        return $row->actions($switch);
    }
    public function actions(Action ...$actions): static
    {
        if ($this->on !== null && count($actions) !== 1) { throw new \InvalidArgumentException('A switch row has one control: its switch.'); }
        return parent::actions(...$actions);
    }
    /** The one control a switch row may have after its switch, such as a link that changes what the switch uses. */
    public function also(Action $action): self
    {
        if ($this->on === null || ($switch = $this->controls[0] ?? null) === null) { throw new \LogicException('Only a switch row takes a control after its switch.'); }
        parent::actions($switch, $action);
        // The switch keeps the row's actions key, so a request names it as before; the other control gets its own.
        return $this->schema([Actions::make([$switch])->key(fn (): string => $this->getKey(false).'-actions'), Actions::make([$action])->key(fn (): string => $this->getKey(false).'-also')]);
    }
    /** The switch goes before the value; any other control stays in the action column. */
    protected function placed(array $shown): array
    {
        if ($this->on === null || ($switch = $this->controls[0] ?? null) === null) { return parent::placed($shown); }
        $parts = $this->getChildSchema()->getComponents(withHidden: true);
        $rest = array_values(array_filter($shown, static fn (Action $a): bool => $a !== $switch));
        $inline = in_array($switch, $shown, true) ? $parts[0]->toHtml() : null;
        $column = $rest === [] ? null : (count($parts) > 1 ? $parts[1] : $parts[0])->toHtml();
        return [$inline, $column, count($rest)];
    }
    /** Who sets it is a fact, so it goes on the visible line under the value, never in the "?". A reader with the
     * control sets it, so they get no such line. */
    protected function shownValue(): Value
    {
        $controls = array_filter($this->controls, static fn (Action $a): bool => $a->isVisible());
        return $controls === [] && $this->setBy !== null ? parent::shownValue()->then($this->setBy) : parent::shownValue();
    }
    public function toEmbeddedHtml(): string
    {
        if ($this->setBy === null) { throw new \LogicException('A setting says who sets it: use SettingsRow::setting().'); }
        if ($this->on !== null && ($switch = $this->controls[0] ?? null) !== null) {
            $why = $this->blocked !== null ? $this->anchor().'-why' : $this->blockedBy?->anchor();
            $switch->view('domains::components.panel.switch', ['on' => $this->on, 'name' => $this->rowLabel, 'why' => $why]);
        }
        return parent::toEmbeddedHtml();
    }
    private static function sentence(string $text): string
    {
        $text = trim($text);
        if ($text === '' || !str_ends_with($text, '.')) { throw new \InvalidArgumentException('Who sets a setting is one sentence.'); }
        return $text;
    }
}
