<?php
// Adapted from Enom 444f31c Admin/Pages/EnomSetup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\EmbeddedTable};
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Registrars: every registrar account (Domains server) with its status and balance. The slug keeps the old address. */
class RegistrarSetup extends Page implements HasTable
{
    use InteractsWithTable;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=4;
    protected static ?string $title='Registrars';
    protected static ?string $slug='registrar-setup';
    /** Row facts per server for one render. */
    private array $rows=[];
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();}
    protected function getHeaderActions(): array
    { return [Action::make('add_account')->label('Add registrar account')->url(self::resourceUrl(\App\Admin\Resources\ServerResource::class,'create'))]; }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$components=[];
        if ($missing=ContactDetails::missingRequiredProperties()) {
            $open=self::resourceUrl(\App\Admin\Resources\CustomPropertyResource::class,'index');
            $components[]=Callout::make("Customers can't enter ".implode(', ',$missing).'. Add them as custom properties.')->warning()->columnSpanFull()
                ->footer($open?new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">Open custom properties</x-filament::button>',['url'=>$open])):null);
        }
        return $schema->components([...$components,EmbeddedTable::make()]);
    }
    public function table(Table $table): Table
    {
        $row=fn(Server $s)=>$this->row($s);
        $missing=static fn(TextColumn $column)=>$column->color(fn($state,Server $record)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null);
        return $table->query(Server::query()->where('extension','Domains'))->defaultSort('id')->paginated(false)->heading('Accounts')
            ->emptyStateHeading('No registrar accounts')->emptyStateDescription(null)
            ->recordUrl(fn(Server $record)=>RegistrarAccount::getUrl(['account'=>$record->id]))
            ->columns([
                TextColumn::make('name')->label('Account')->weight('medium')->wrap()->description(fn(Server $record)=>$row($record)['summary'])
                    ->suffix(fn(Server $record)=>$row($record)['demo']?DemoData::tag(inline:true):null),
                TextColumn::make('mode')->label('Mode')->state(fn(Server $record)=>$row($record)['connected']?RegistrarFacts::mode($row($record)['account']):null),
                TextColumn::make('status')->label('Status')->badge()->state(fn(Server $record)=>$row($record)['status']['label'])
                    ->color(fn(Server $record)=>$row($record)['status']['tone'])->description(fn(Server $record)=>$row($record)['status']['reason']),
                $missing(TextColumn::make('balance')->label('Balance')->state(fn(Server $record)=>$row($record)['balance']?($row($record)['balance']['value']??Missing::LABEL):null)
                    ->description(function(Server $record)use($row){$b=$row($record)['balance'];if(!$b){return null;}
                        return $b['value']===null?$b['cause']:($b['low']?RegistrarFacts::warn('Low balance'):'Checked '.$b['checked']);})),
                TextColumn::make('due')->label('Due in 30 days')->state(fn(Server $record)=>$row($record)['balance']['due']??null)
                    ->description(fn(Server $record)=>($row($record)['balance']['without_cost']??0)>0?RegistrarFacts::warn($row($record)['balance']['without_cost'].' without a cost'):null),
            ])
            ->recordActions([
                Action::make('add_funds')->label('Add funds')->link()->icon('heroicon-m-arrow-top-right-on-square')->iconPosition(IconPosition::After)
                    ->visible(fn(Server $record)=>$row($record)['balance']!==null)->url(fn(Server $record)=>$row($record)['balance']['funds']??null)->openUrlInNewTab()
                    ->extraAttributes(['rel'=>'noopener noreferrer']),
                Action::make('connect')->label('Connect')->link()->visible(fn(Server $record)=>$row($record)['account']===null)
                    ->url(fn(Server $record)=>RegistrarAccount::getUrl(['account'=>$record->id])),
            ]);
    }
    public function getHeading(): HtmlString { return new HtmlString(e(static::$title).RegistrarFacts::STYLE); }
    /** @return array{account:?Account,status:array,connected:bool,summary:string,demo:bool,balance:?array} */
    private function row(Server $s): array
    {
        if (isset($this->rows[$s->id])) { return $this->rows[$s->id]; }
        $a=Account::where('server_id',$s->id)->first();$status=RegistrarFacts::status($s,$a);$connected=RegistrarFacts::connected($a,$status);
        return $this->rows[$s->id]=['account'=>$a,'status'=>$status,'connected'=>$connected,'summary'=>RegistrarFacts::summary($s,$a),
            'demo'=>$a!==null&&DemoData::is($a),'balance'=>$connected?RegistrarFacts::balance($a):null];
    }
    private static function resourceUrl(string $resource,string $page): ?string
    { try { return class_exists($resource)?$resource::getUrl($page):null; } catch (\Throwable) { return null; } }
}
