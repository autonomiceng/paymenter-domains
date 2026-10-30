<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\{Schema, Components\Section};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HelpHint, Staff, PslActions, PslFacts};
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
    protected static bool $shouldRegisterNavigation = false;
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
        $at = $list->nextAllowedAt(); $now = app(Clock::class)->now()->getTimestamp(); $state = PslFacts::state($list);
        return $schema->components([
            Section::make(match ($state) { 'New discovery paused' => 'Out of date', default => $state })->description(match ($state) {
                'Saved list damaged' => 'The saved list is damaged. Monitoring won’t find new domains until you download a fresh copy.',
                'Saved list needs review' => 'The saved list can’t be read. Monitoring won’t find new domains until you download a fresh copy and review it.',
                'Not downloaded yet' => 'Monitoring won’t find new domains until the list is downloaded.',
                'New discovery paused' => 'The list is over '.Lists::MAX_AGE_DAYS.' days old. Monitoring won’t find new domains until you download a fresh copy.',
                default => 'The list is current. Out of date after '.PslFacts::time($list->expiresAt()).'.',
            })->icon(match ($state) { 'Current' => 'heroicon-o-check-circle', 'Not downloaded yet' => 'heroicon-o-arrow-down-tray', default => 'heroicon-o-exclamation-triangle' })->iconColor(match ($state) { 'New discovery paused' => 'danger', default => 'gray' })->schema($entries)->columns(2),
            Section::make('Updates and source')->description($at !== null && $now < $at ? 'Next update allowed: '.PslFacts::time($at).'.' : 'You can update now.')
                ->schema([TextEntry::make('help')->label('How updates work')->state('Updates run weekly, at most once a day. If a download is refused, the saved list stays. List data from publicsuffix.org, MPL-2.0.')
                    ->hintAction(HelpHint::make('Download now counts toward the once a day. If the source reports no change, only the check time updates. After '.Lists::MAX_AGE_DAYS.' days the full list downloads again so its age resets. This page does not find hostnames.'))])
        ]);
    }
}
