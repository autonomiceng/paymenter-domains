<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\{Schema, Components\Section};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff, PslActions, PslFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList as Lists;
/** Public suffix list status. Its actions are shared with the System checks row on registrar account pages. */
class PublicSuffixList extends Page
{
    public const VIEW = 'admin.domains.watch.view';
    public const MANAGE = 'admin.domains.watch.manage';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'Public suffix list';
    protected static ?int $navigationSort = 50;
    protected static ?string $slug = 'public-suffix-list';
    public static function canAccess(): bool { return Staff::can(self::VIEW); }
    public function mount(): void { abort_unless(self::canAccess(), 403); }
    protected function getHeaderActions(): array
    {
        $reset = function (): void { unset($this->cachedSchemas['content']); };
        return [PslActions::download($reset), PslActions::accept($reset), PslActions::source($reset)];
    }
    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(), 403); $list = app(Lists::class); $entries = [];
        foreach (PslFacts::rows($list) as $label => $value) {
            $entries[] = TextEntry::make('psl_'.count($entries))->label($label)->state((string) $value)->extraAttributes(['class' => 'break-all']);
        }
        $at = $list->nextAllowedAt(); $now = app(Clock::class)->now()->getTimestamp();
        return $schema->components([
            Section::make(PslFacts::state($list))->description(match (PslFacts::state($list)) {
                'Saved list damaged' => 'The saved bytes cannot be verified. New discovery is paused. See the README to restore a verified backup.',
                'Saved list needs review' => 'The saved list cannot be parsed with the current parser. New discovery is paused. Download a valid replacement and review it.',
                'Not downloaded yet' => 'New discovery is paused until a validated list is downloaded.',
                'New discovery paused' => 'The list is more than 90 days old. Download a validated list before new discovery can resume.',
                'Out of date warning' => 'The saved list is more than 30 days old. New discovery pauses after '.PslFacts::time((int) $list->current()->validated_at + 90 * 86400).' unless a validated list is downloaded.',
                default => 'A validated list is available for future discovery. Out of date after '.PslFacts::time((int) $list->current()->validated_at + 30 * 86400).'.',
            })->icon(match (PslFacts::state($list)) { 'Current' => 'heroicon-o-check-circle', 'Not downloaded yet' => 'heroicon-o-arrow-down-tray', default => 'heroicon-o-exclamation-triangle' })->iconColor(match (PslFacts::state($list)) { 'New discovery paused' => 'danger', 'Out of date warning' => 'warning', default => 'gray' })->schema($entries)->columns(2),
            Section::make('Updates and source')->description($at !== null && $now < $at ? 'Next update allowed: '.PslFacts::time($at).'.' : 'You can update now.')
                ->schema([TextEntry::make('help')->hiddenLabel()->markdown()->state(
                    "Updates run weekly. At most one download is attempted in any 24 hours, including Download now.\n\n".
                    "If a download is refused, the saved list is kept.\n\n".
                    "If the source reports no change, only the check time is updated. After 30 days the full list is downloaded again so its age can reset.\n\n".
                    'The Public Suffix List is MPL-2.0 licensed data from publicsuffix.org. This page does not discover hostnames.')])
        ]);
    }
}
