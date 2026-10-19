<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema, Components\Section};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff, PslFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as Lists, PslTransport};
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
    private static function requireManage(): void
    { abort_unless(Staff::can(self::VIEW) && Staff::can(self::MANAGE), 403); }
    protected function getHeaderActions(): array
    {
        return [Action::make('update')->label('Update now')->visible(fn() => Staff::can(self::MANAGE))
            ->requiresConfirmation()->modalDescription('This uses the one update allowed in any 24 hours.')
            ->tooltip(fn() => ($at = app(Lists::class)->nextAllowedAt()) !== null && app(Clock::class)->now()->getTimestamp() < $at ? 'Next update allowed: '.PslFacts::time($at) : null)
            ->disabled(fn() => ($at = app(Lists::class)->nextAllowedAt()) !== null && app(Clock::class)->now()->getTimestamp() < $at)
            ->action(function (): void {
                self::requireManage(); $code = app(Lists::class)->update(); unset($this->cachedSchemas['content']);
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
                        DB::table('domains_psl_status')->where('id', 1)->lockForUpdate()->firstOrFail(); self::requireManage();
                        Setting::updateOrCreate(['settingable_type' => null, 'settingable_id' => null, 'key' => Lists::SETTING], ['type' => 'string', 'value' => $data['url']]);
                    });
                    unset($this->cachedSchemas['content']); Notification::make()->title('Public suffix list source saved')->send();
                })];
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
