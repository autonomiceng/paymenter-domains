<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Support\Enums\IconPosition;
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
        $names=\App\Models\Server::where('extension','Domains')->pluck('name','id');
        foreach(Account::orderBy('server_id')->get() as $a){
            $facts=Balances::facts($a,app(Clock::class)->now());$id=(int)$a->server_id;
            $entries=[];
            foreach(['Balance'=>$facts['balance'],'As of'=>$facts['as_of'],'Status'=>$facts['status'],
                'Renewals in the next 30 days and open obligations'=>$facts['total'],'Unknown renewals'=>(string)$facts['unknown'],
                'Last read error'=>$facts['error']] as $label=>$value){$entries[]=TextEntry::make('s'.$id.'_'.count($entries))->label($label)->state($value);}
            $sections[]=Section::make($names[$id] ?? 'Server #'.$id)
                ->description(TldFacts::registrar($a->registrar_key).' · Server #'.$id.' · '.($a->environment==='live'?'Live':'Test'))->key('account_'.$id)->columns(2)->schema([...$entries,
                    Actions::make([
                        Action::make('funds_'.$id)->label('Add funds')->color('gray')->outlined()->icon('heroicon-m-arrow-top-right-on-square')->iconPosition(IconPosition::After)
                            ->tooltip("Opens the registrar's site in a new tab")->url(Balances::fundsUrl($a->registrar_key))->openUrlInNewTab()->extraAttributes(['rel'=>'noopener noreferrer']),
                        Action::make('refresh_'.$id)->label('Refresh now')->action(function()use($id):void {
                            Staff::requireView();$code=app(Balances::class)->refresh($id);
                            $body=match($code){'configuration_unavailable'=>'Open Registrar setup to check this account and use Read balance.',
                                'test_call_refused'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::refusedFacts().' No provider request was made.',default=>null};
                            \Filament\Notifications\Notification::make()->title(match($code){'refreshed'=>'Balance refreshed','rate_limited'=>'Wait before refreshing again',
                                'test_call_refused'=>'Enom TEST request refused','configuration_unavailable'=>'Configuration unavailable',default=>'Balance read failed'})->body($body)->send();
                        }),
                    ])->columnSpanFull()])->columnSpanFull();
        }
        return $schema->components($sections?:[Section::make('No registrar accounts')]);
    }
}
