<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** Old address of the balances page. Balances now show on Registrars. */
class RegistrarBalances extends Page
{
    protected static ?string $slug='registrar-balances';
    protected static bool $shouldRegisterNavigation=false;
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();$this->redirect(RegistrarSetup::getUrl());}
}
