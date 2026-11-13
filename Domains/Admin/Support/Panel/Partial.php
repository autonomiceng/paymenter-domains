<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel;
use Filament\Schemas\Components\Component;
use Filament\Support\Components\Contracts\HasEmbeddedView;
/** A section part a shared partial view already draws, such as the registrar records head or the public DNS evidence.
 * A record Detail page places it between its rows; the partial keeps its own markup and data, and nothing else is
 * written inline. Only the extension's own partial views can be placed (domains::partials.* and domains::admin.*). */
final class Partial extends Component implements HasEmbeddedView
{
    final public function __construct(private string $partial, private array $data) {}
    /** @param array<string,mixed> $data */
    public static function make(string $view, array $data = [], ?string $key = null): self
    {
        if (!preg_match('/^domains::(partials|admin)\.[a-z0-9.-]+$/', $view) || !view()->exists($view)) { throw new \InvalidArgumentException('A partial is one of the extension\'s own partial views.'); }
        $part = app(self::class, ['partial' => $view, 'data' => $data]);
        $part->configure();
        return $part->key($key ?? 'partial-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', substr($view, 9).'-'.md5(serialize(array_keys($data)))), '-'));
    }
    public function toEmbeddedHtml(): string
    { return '<div class="domains-panel-partial" data-piece="partial">'.view($this->partial, $this->data)->render().'</div>'; }
}
