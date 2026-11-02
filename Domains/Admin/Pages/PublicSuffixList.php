<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\Width;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet,Staff,PslActions,HelpHint};
use Paymenter\Extensions\Servers\Domains\Services\PslRebaseline;
/** Registrar sites use System checks; monitoring-only sites retain the same exceptional review. */
class PublicSuffixList extends Page
{
    public const VIEW = 'admin.domains.watch.view';
    public const MANAGE = 'admin.domains.watch.manage';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'Public suffix list';
    protected static ?string $slug = 'public-suffix-list';
    protected Width|string|null $maxContentWidth = Sheet::WIDTH;
    public static function canAccess(): bool { return Staff::can(self::VIEW); }
    public static function shouldRegisterNavigation(): bool
    { return !Server::where('extension','Domains')->exists()&&app(PslRebaseline::class)->preview()!==null; }
    public function mount(): void
    { abort_unless(self::canAccess(),403);if(Server::where('extension','Domains')->exists()){$this->redirect(RegistrarSetup::getUrl());} }
    protected function getHeaderActions(): array
    { return [PslActions::accept(function():void {unset($this->cachedSchemas['content']);})]; }
    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(),403);$row=PslActions::row(fn()=>null);
        return $schema->components([Sheet::section('Public suffix list',[
            Sheet::row(TextEntry::make('status')->state($row['value'])->color($row['tone'])->hintAction(HelpHint::make($row['help']))),
        ],$row['cause'])]);
    }
}
