<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\{TextInput, Hidden, Textarea};
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema, Components\Section};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff, PslFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as Lists, PslTransport, PslRebaseline, PslFailure};
class PublicSuffixList extends Page
{
    public const VIEW = 'admin.domains.watch.view';
    public const MANAGE = 'admin.domains.watch.manage';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'Public suffix list';
    protected static ?int $navigationSort = 50;
    protected static ?string $slug = 'public-suffix-list';
    private array|false|null $reviewPreview = false;
    private function review(): ?array
    { if ($this->reviewPreview === false) { $this->reviewPreview = app(PslRebaseline::class)->preview(); } return $this->reviewPreview; }
    public static function canAccess(): bool { return Staff::can(self::VIEW); }
    public function mount(): void { abort_unless(self::canAccess(), 403); }
    private static function requireManage(): void
    { abort_unless(Staff::can(self::VIEW) && Staff::can(self::MANAGE), 403); }
    protected function getHeaderActions(): array
    {
        return [Action::make('update')->label('Update now')->visible(fn() => Staff::can(self::MANAGE))
            ->requiresConfirmation()->modalDescription('This uses the one update allowed in any 24 hours.')
            ->tooltip(fn() => ($at = app(Lists::class)->nextAllowedAt()) !== null && app(Clock::class)->now()->getTimestamp() < $at ? 'Next update allowed: '.PslFacts::time($at) : null)
            ->disabled(fn() => ($at = app(Lists::class)->nextAllowedAt()) !== null && app(Clock::class)->now()->getTimestamp() < $at)
            ->action(function (): void {
                self::requireManage(); $code = app(Lists::class)->update(); unset($this->cachedSchemas['content']); $this->reviewPreview = false;
                $notice = Notification::make()->title(PslFacts::result($code));
                (in_array($code, ['updated', 'identical', 'not_modified'], true) ? $notice->success() : $notice->warning())->send();
            }),
            Action::make('source')->label('Change source')->color('gray')->visible(fn() => Staff::can(self::MANAGE))
                ->schema([TextInput::make('url')->label('HTTPS source URL')->required()->maxLength(2048)->default(fn() => app(Lists::class)->source())->helperText('Default: '.Lists::SOURCE)
                    ->rules([fn() => function ($attribute, $value, $fail): void { if (!is_string($value) || !PslTransport::validUrl($value)) { $fail('Use an HTTPS URL with no credentials, query or fragment.'); } }])])
                ->action(function (array $data): void {
                    self::requireManage(); abort_unless(PslTransport::validUrl($data['url']), 422);
                    DB::transaction(function () use ($data): void {
                        // Serialize extension-wide settings with fetch admission; saving never starts a download.
                        $status = DB::table('domains_psl_status')->where('id', 1)->lockForUpdate()->firstOrFail(); self::requireManage();
                        if (app(Lists::class)->source() !== $data['url']) {
                            DB::table('domains_psl_status')->where('id', 1)->update(['attempt_token' => null, ...($status->last_result === 'checking' ? ['last_result' => 'superseded'] : [])]);
                        }
                        Setting::updateOrCreate(['settingable_type' => null, 'settingable_id' => null, 'key' => Lists::SETTING], ['type' => 'string', 'value' => $data['url']]);
                    });
                    unset($this->cachedSchemas['content']); $this->reviewPreview = false; Notification::make()->title('Public suffix list source saved')->send();
                }),
            Action::make('accept')->label('Accept this list')->color('warning')
                ->visible(fn() => Staff::can(self::MANAGE) &&  $this->review() !== null)
                ->modalDescription('Review the removed rules. Acceptance applies only to these exact downloaded bytes and records your reason.')
                ->schema(function (): array {
                    self::requireManage(); $preview =  $this->review();
                    return [Hidden::make('revision')->default($preview['revision'] ?? ''),
                        TextEntry::make('changes')->label('Removed rules')->state(self::removedRules($preview))->listWithLineBreaks(),
                        TextInput::make('confirmation')->label('Type '.substr($preview['revision'] ?? '', 0, 12).' to confirm')->required()->in([substr($preview['revision'] ?? '', 0, 12)]),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(1000)->rules([fn() => function ($attribute, $value, $fail): void { if (!\Paymenter\Extensions\Servers\Domains\Services\StaffReason::valid($value,1,1000)) { $fail('Use at most 1000 characters and valid UTF-8 for the reason.'); } }])];
                })->action(function (array $data): void {
                    self::requireManage();
                    try { app(PslRebaseline::class)->accept(auth()->user(), $data['revision'], $data['confirmation'], $data['reason']); }
                    catch (PslFailure $e) { $this->reviewPreview = false; Notification::make()->title(PslFacts::result($e->safeCode))->danger()->send(); return; }
                    unset($this->cachedSchemas['content']); $this->reviewPreview = false; Notification::make()->title('Public suffix list accepted')->success()->send();
                })];
    }
    private static function removedRules(?array $preview): array
    {
        if ($preview === null) { return ['The candidate changed. Reload before accepting.']; }
        $lines = [$preview['icu_incompatible'] ? 'The saved rules are incompatible with the current international domain parser. Counts compare historical source identities.' : 'More than 5% of previously saved rules were removed.'];
        $lines[] = 'Candidate SHA256: '.$preview['revision']; $lines[] = 'Saved SHA256: '.$preview['baseline_revision'];
        $lines[] = 'Source: '.$preview['source_url']; $lines[] = 'Downloaded: '.PslFacts::time($preview['fetched_at']);
        foreach ($preview['after_counts'] as $section => $count) { $lines[] = strtoupper($section).' rules: '.$preview['before_counts'][$section].' → '.$count; }
        foreach ($preview['removed'] as $section => $removed) {
            $lines[] = strtoupper($section).': '.$removed['count'].' removed. Samples (up to 20): '.implode(', ', $removed['samples']);
        }
        foreach ($preview['rejected'] as $section => $rejected) { $lines[] = strtoupper($section).' rejected by current parser: '.$rejected['count'].'. Samples (up to 20): '.implode(', ', $rejected['samples']); }
        return $lines;
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
                    "Updates run weekly. At most one download is attempted in any 24 hours, including Update now.\n\n".
                    "If a download is refused, the saved list is kept.\n\n".
                    "If the source reports no change, only the check time is updated. After 30 days the full list is downloaded again so its age can reset.\n\n".
                    'The Public Suffix List is MPL-2.0 licensed data from publicsuffix.org. This page does not discover hostnames.')])
        ]);
    }
}
