<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HistoryTable,Staff,TldFacts};
/** The History tab of a registrar account: its settings and price changes, newest first. */
class RegistrarHistory extends RegistrarAccount implements HasTable
{
    use InteractsWithTable;
    protected static ?string $slug='registrar-account-history';
    public static function canAccess(): bool { return Staff::can(Staff::POLICY); }
    public static function getRoutePath(Panel $panel): string { return '/registrar-account/{account}/history'; }
    public function content(Schema $schema): Schema { Staff::requireView();return $schema->components([EmbeddedTable::make()]); }
    public function table(Table $table): Table { return HistoryTable::apply($table,fn()=>TldFacts::history($this->account)); }
}
