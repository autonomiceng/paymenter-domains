<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Section,Components\Actions};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances as Balances;
class RegistrarBalances extends Page
{
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-banknotes';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $title='Registrar balances';
    protected static ?string $slug='registrar-balances';
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();}
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$sections=[];
        foreach(Account::orderBy('server_id')->get() as $a){
            $facts=Balances::facts($a,app(Clock::class)->now());$id=(int)$a->server_id;
            $entries=[];
            foreach(['Balance'=>$facts['balance'],'As of'=>$facts['as_of'],'Status'=>$facts['status'],
                'Renewals in the next 30 days and open obligations'=>$facts['total'],'Unknown renewals'=>(string)$facts['unknown'],
                'Last read error'=>$facts['error']] as $label=>$value){$entries[]=TextEntry::make('s'.$id.'_'.count($entries))->label($label)->state($value);}
            $sections[]=Section::make(TldFacts::registrar($a->registrar_key).' · account #'.$id)
                ->description($a->environment==='live'?'Live':'Test')->key('account_'.$id)->columns(2)->schema([...$entries,
                    Actions::make([
                        Action::make('funds_'.$id)->label('Add funds')->url(Balances::fundsUrl($a->registrar_key))->openUrlInNewTab()->extraAttributes(['rel'=>'noopener noreferrer']),
                        Action::make('refresh_'.$id)->label('Refresh now')->action(function()use($id):void {
                            Staff::requireView();$code=app(Balances::class)->refresh($id);
                            \Filament\Notifications\Notification::make()->title(match($code){'refreshed'=>'Balance refreshed','rate_limited'=>'Wait before refreshing again','configuration_unavailable'=>'Configuration unavailable',default=>'Balance read failed'})->send();
                        }),
                    ])->columnSpanFull()])->columnSpanFull();
        }
        return $schema->components($sections?:[Section::make('No registrar accounts')]);
    }
}
