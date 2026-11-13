<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PslActions,PslRows,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\DetailPage;
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;
use Paymenter\Extensions\Servers\Domains\Services\{PslRebaseline,PublicSuffixList as Lists};
/** Registrar sites use System checks; monitoring-only sites retain the same exceptional review. */
class PublicSuffixList extends DetailPage
{
    public const VIEW = 'admin.domains.watch.view';
    public const MANAGE = 'admin.domains.watch.manage';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'Public suffix list';
    protected static ?string $slug = 'public-suffix-list';
    public static function canAccess(): bool { return Staff::can(self::VIEW); }
    public static function shouldRegisterNavigation(): bool
    { return !Server::where('extension', 'Domains')->exists() && app(PslRebaseline::class)->preview() !== null; }
    public function mount(): void
    { abort_unless(self::canAccess(), 403); if (Server::where('extension', 'Domains')->exists()) { $this->redirect(RegistrarSetup::getUrl()); } }
    protected function head(): Head { abort_unless(self::canAccess(), 403); return Head::make('Public suffix list', Reader::Staff); }
    protected function actions(): array
    { return [PslActions::accept(function (): void { unset($this->cachedSchemas['content']); })]; }
    protected function sections(): array { abort_unless(self::canAccess(), 403); return PslRows::sections(app(Lists::class)); }
}
