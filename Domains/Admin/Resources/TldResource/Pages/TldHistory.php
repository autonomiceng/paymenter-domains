<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HistoryTable,Staff,TldFacts};
/** The History tab of a TLD: its settings and price changes, newest first. */
class TldHistory extends ViewTld implements HasTable
{
    use InteractsWithTable;
    public function content(Schema $schema): Schema {Staff::requireView();return $schema->components([EmbeddedTable::make()]);}
    public function table(Table $table): Table
    {return HistoryTable::apply($table,fn()=>TldFacts::history((int)$this->getRecord()->server_id,(int)$this->getRecord()->id));}
}
