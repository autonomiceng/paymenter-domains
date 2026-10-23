<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema, Components\Section};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Services\DnsResolverSettings;
class DnsResolver extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-signal';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'DNS resolver';
    protected static ?int $navigationSort = 51;
    protected static ?string $slug = 'dns-resolver';
    #[\Livewire\Attributes\Locked]
    public ?array $lastCheck = null;
    public static function canAccess(): bool { return Staff::can(PublicSuffixList::VIEW); }
    public function mount(): void { abort_unless(self::canAccess(), 403); }
    private static function requireManage(): void
    { abort_unless(self::canAccess() && Staff::can(PublicSuffixList::MANAGE), 403); }
    protected function getHeaderActions(): array
    {
        return [Action::make('configure')->label('Configure DNS resolver')->visible(fn() => Staff::can(PublicSuffixList::MANAGE))
            ->schema([
                TextInput::make('url')->label('HTTPS endpoint URL')->required()->maxLength(2048)
                    ->default(fn() => app(DnsResolverSettings::class)->configuration()[0]?->url ?? DohEndpoint::URL)
                    ->helperText('Default: '.DohEndpoint::URL.' · No credentials, query, fragment or alternate port.')
                    ->rules([fn() => function ($attribute, $value, $fail): void {
                        try { new DohEndpoint($value, DohEndpoint::IP); } catch (\Throwable) { $fail('Use an HTTPS URL with a public resolver hostname.'); }
                    }]),
                TextInput::make('ip')->label('Public bootstrap IP address')->required()->maxLength(45)
                    ->default(fn() => app(DnsResolverSettings::class)->configuration()[0]?->ip ?? DohEndpoint::IP)
                    ->helperText('Default: '.DohEndpoint::IP.'. For a custom endpoint, enter its public IP. HTTPS still verifies the hostname.')
                    ->rules([fn() => function ($attribute, $value, $fail): void {
                        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) { $fail('Use a public IPv4 or IPv6 address.'); }
                    }]),
            ])->action(function (array $data): void {
                self::requireManage(); app(DnsResolverSettings::class)->save($data['url'], $data['ip']);
                $this->lastCheck = null; Notification::make()->title('DNS resolver settings saved')->success()->send();
            }),
            Action::make('check')->label('Check DNS resolver')->visible(fn() => Staff::can(PublicSuffixList::MANAGE))
                ->action(function (): void {
                    self::requireManage(); $this->lastCheck = app(DnsResolverSettings::class)->check();
                    $notice = Notification::make()->title($this->lastCheck['message']);
                    ($this->lastCheck['ok'] ? $notice->success() : $notice->warning())->send();
                })];
    }
    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(), 403);
        return $schema->components([
            Section::make(fn() => app(DnsResolverSettings::class)->configuration()[0] ? 'DNS-over-HTTPS configured' : "Using the server's resolver")
                ->description(fn() => app(DnsResolverSettings::class)->configuration()[1] ?? 'DNS-over-HTTPS is tried first. Connection and response failures use the server’s resolver. Valid DNS error responses stay unknown.')
                ->schema([TextEntry::make('endpoint')->label('Endpoint')->state(fn() => app(DnsResolverSettings::class)->configuration()[0]?->url ?? 'Not configured'),
                    TextEntry::make('bootstrap')->label('Public bootstrap IP address')->state(fn() => app(DnsResolverSettings::class)->configuration()[0]?->ip ?? 'Not configured')])->columns(2),
            Section::make('Resolver check')->description('Check DNS resolver makes one query for example.com. A failed check does not query the server’s resolver.')
                ->schema([TextEntry::make('result')->label('Last check this visit')->state(fn() => $this->lastCheck['message'] ?? 'Not checked this visit'),
                    TextEntry::make('privacy')->label('Query privacy')->state('Checked domain names are sent to the configured resolver.')]),
        ]);
    }
}
