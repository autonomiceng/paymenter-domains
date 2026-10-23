<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Services\ActivationFacts;
/** Local facts and review steps only; this page has no activation actions. */
class ActivationStatus extends Page
{
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-information-circle';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $title='Activation status';
    protected static ?string $slug='domains-activation-status';
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();}
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$sections=[];
        foreach(ActivationFacts::all() as $key=>$groups){foreach($groups as $group=>$facts){
            $entries=[];foreach($facts as $label=>$step){$entries[]=TextEntry::make('gate_'.count($sections).'_'.count($entries))->label($label)->state($step);}
            $sections[]=Section::make(TldFacts::registrar($key).' · '.$group)
                ->description('Saved local facts and required reviews. No provider requests are made. A completed review does not enable other gates.')
                ->columns(1)->schema($entries)->columnSpanFull();
        }}
        return $schema->components($sections);
    }
}
