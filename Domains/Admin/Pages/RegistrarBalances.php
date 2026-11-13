<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\DetailPage;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;
/** Old address of the balances page. Balances now show on Registrars, so it only sends staff there. */
class RegistrarBalances extends DetailPage
{
    protected static ?string $slug='registrar-balances';
    protected static bool $shouldRegisterNavigation=false;
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();$this->redirect(RegistrarSetup::getUrl());}
    protected function head(): Head { return Head::make('Registrar balances', Reader::Staff); }
    protected function sections(): array { return []; }
}
