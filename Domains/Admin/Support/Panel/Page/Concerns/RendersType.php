<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Concerns;
use Filament\Actions\{Action, ActionGroup};
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;
/** The part every page type shares: the head, the header actions and the frame. All of it is final, so a page can't
 * draw its own head, header, footer, widgets or view; it only answers head(), actions() and poll() with data. */
trait RendersType
{
    /** The title, its tag, and the line under it with the record's own state. */
    abstract protected function head(): Head;
    /** Native Filament actions beside the title. Their modals, authorisation and refresh hooks are their own.
     * @return list<Action|ActionGroup> */
    protected function actions(): array { return []; }
    /** A page that waits on a check names its public refresh method and how often: ['pollCheck', 5]. Seconds are
     * 5 or 60; a record Detail page also takes 2 and 10, and true third keeps reading while the tab is hidden, for
     * the bounded time the page itself decides. Null for no refresh. @return ?array{0:string,1:int,2?:bool} */
    protected function poll(): ?array { return null; }

    final public function getTitle(): string|Htmlable
    {
        $title = $this->head()->title;
        $active = array_values(array_filter($this->getSubNavigation(), static fn ($item): bool => $item->isActive()));
        return count($active) === 1 ? $active[0]->getLabel().' - '.$title : $title;
    }
    final public function getHeading(): string|Htmlable|null { return $this->head()->heading(); }
    final public function getSubheading(): string|Htmlable|null { return $this->head()->line(); }
    final protected function getHeaderActions(): array
    {
        $actions = array_values($this->actions());
        foreach ($actions as $action) {
            if (!$action instanceof Action && !$action instanceof ActionGroup) { throw new \InvalidArgumentException('A header action is a native Filament action.'); }
        }
        return $actions;
    }
    final public function getHeader(): ?View
    {
        $poll = $this->poll();
        $every = $this instanceof \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordDetailPage ? [2, 5, 10, 60] : [5, 60];
        if ($poll !== null && (!is_string($poll[0] ?? null) || !method_exists($this, $poll[0]) || !in_array($poll[1] ?? null, $every, true) || !is_bool($poll[2] ?? false))) {
            throw new \InvalidArgumentException('A page polls one of its own public methods every '.implode(', ', $every).' seconds.');
        }
        return view('domains::components.panel.page-head', ['poll' => $poll]);
    }
    final public function getFooter(): ?View { return null; }
    final protected function getHeaderWidgets(): array { return []; }
    final protected function getFooterWidgets(): array { return []; }
    final public function getView(): string { return 'filament-panels::pages.page'; }
    final public function getMaxContentWidth(): Width|string|null { return Sheet::WIDTH; }
    /** The type's class on the page, plus Filament's own view or list class so a host skin styles one type one way. */
    final public function getPageClasses(): array { return [...parent::getPageClasses(), ...static::PAGE_CLASSES]; }

    /** The notices a page says above its table: each section opens with a notice or holds the steps it asks for as a trace.
     * @param list<mixed> $notices @return list<\Filament\Schemas\Components\Component> */
    private static function noticesAbove(array $notices, string $where, string $key): array
    {
        $sections = self::sectionsOnly($notices, $where);
        foreach ($sections as $section) {
            $first = $section->rows[0] ?? null;
            if ($section->hasPartial()) { throw new \InvalidArgumentException('A partial view belongs on a record Detail page.'); }
            if (!$section->hasTrace() && !($first instanceof Row && Section::isNotice($first))) { throw new \InvalidArgumentException('A section '.strtolower($where).' opens with a notice or holds steps.'); }
        }
        return $sections === [] ? [] : [Sheet::single(array_map(static fn (Section $s) => $s->toSchema(), $sections), $key)];
    }

    /** @param list<mixed> $sections @return list<Section> */
    private static function sectionsOnly(array $sections, string $where): array
    {
        $sections = array_values($sections);
        foreach ($sections as $section) {
            if (!$section instanceof Section) { throw new \InvalidArgumentException($where.' holds sections only.'); }
        }
        return $sections;
    }
}
