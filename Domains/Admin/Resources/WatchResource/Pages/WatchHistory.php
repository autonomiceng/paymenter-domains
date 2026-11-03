<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HistoryTable,WatchFacts};
/** The History tab of a monitored domain, newest first. */
class WatchHistory extends ViewWatch implements HasTable
{
    use InteractsWithTable;
    public function content(Schema $schema): Schema {return $schema->components([EmbeddedTable::make()]);}
    public function table(Table $table): Table
    {
        return HistoryTable::apply($table,function():?array {
            $rows=WatchFacts::history($this->getRecord());
            return $rows===null?null:array_map(fn(array $r)=>['time'=>$r['time'],'change'=>$r['event'],'reason'=>(string)$r['note'],'by'=>$r['by']],$rows);
        });
    }
}
