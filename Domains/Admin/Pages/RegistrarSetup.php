<?php
// Adapted from Enom 444f31c Admin/Pages/EnomSetup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Support\Enums\IconPosition;
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{CatalogueSalesNotice,RegistrarFacts,Sheet,Staff,StaffDomainStatus,StaffTabs,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,Filter,QueryListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus,Fact,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,ContactDetails};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Registrars: every registrar account (Domains server) with its status and balance. The slug keeps the old address. */
class RegistrarSetup extends QueryListPage
{
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=2;
    protected static ?string $title='Registrars';
    protected static ?string $slug='registrar-setup';
    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition=\Filament\Pages\Enums\SubNavigationPosition::Top;
    public function getSubNavigation(): array { return StaffTabs::registrars('Accounts'); }
    /** Row facts per server for one render. */
    private array $rows=[];
    /** The menu item stays marked on an account and on Registrar requests. */
    public static function getNavigationItemActiveRoutePattern(): string|array
    { return [static::getRouteName(),RegistrarAccount::getRouteName(),RegistrarHistory::getRouteName(),RegistrarBalances::getRouteName(),ActivationStatus::getRouteName(),OperationResource::getRouteBaseName().'.*']; }
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();}
    protected function head(): Head { return Head::make('Registrars', Reader::Staff); }
    /** Add registrar account: name it here, then connect it on its own page. The stock Create Server form asks for a Paymenter
     * extension and shows no registrar fields, so staff landed on a page that never said what to do next. */
    protected function actions(): array
    {
        $resource=\App\Admin\Resources\ServerResource::class;
        if(self::resourceUrl($resource,'create')===null||!$resource::canCreate()){return [];}
        return [Action::make('add_account')->label('Add registrar account')->modalHeading('Add registrar account?')
            ->modalDescription('Name the account. Next, on its page, use Connect account to choose the registrar and enter its API key.')
            ->schema([\Filament\Forms\Components\TextInput::make('name')->label('Name')->placeholder('Enom live account')->required()->maxLength(255)])
            ->modalSubmitActionLabel('Add')
            ->action(function(array $data)use($resource):void {
                if(!$resource::canCreate()){throw new \Illuminate\Auth\Access\AuthorizationException;}
                $name=trim((string)$data['name']);
                $server=Server::create(['name'=>$name,'extension'=>'Domains','type'=>'server','enabled'=>true]);
                try{
                    \App\Helpers\ExtensionHelper::call($server,'enabled',[$server]);
                    \Filament\Notifications\Notification::make()->success()->title('Registrar account added')->body('Use Connect account to finish.')->send();
                }catch(\Throwable $e){
                    report($e);
                    \Filament\Notifications\Notification::make()->warning()->title('Registrar account added; setup failed')
                        ->body('The account was saved. Ask your site admin to finish Domains setup, then use Connect account.')->send();
                }
                $this->redirect(RegistrarAccount::getUrl(['account'=>$server->id]));
            })];
    }
    protected function notices(): array
    {
        $sections=CatalogueSalesNotice::sections();
        if ($missing=ContactDetails::missingRequiredProperties()) {
            $open=self::resourceUrl(\App\Admin\Resources\CustomPropertyResource::class,'index');
            $sections[]=Section::make('Customer details',[Row::notice('contact-details',Value::state(State::NotSet,Reader::Staff,"Customers can't enter ".implode(', ',$missing).' in Account. Add them as custom properties.'),
                $open?Sheet::link('open_custom_properties','Open custom properties',$open):null)]);
        }
        return $sections;
    }
    protected function query(): Builder { return self::accounts(); }
    protected function rowUrl(Model $record): ?string { return RegistrarAccount::getUrl(['account'=>$record->id]); }
    protected function defaultSort(): ?array { return ['id','asc']; }
    protected function searchLabel(): ?string { return 'Search account'; }
    protected function emptyLine(): array { return ['No registrar accounts','Add one to start selling domains.','No accounts match']; }
    /** A registrar amount keeps its currency first, as saved: "USD 12.00". */
    private static function money(?string $amount): ?Value
    {
        $fact=$amount===null?null:Fact::names([$amount]);
        return $fact===null?null:Value::fact($fact);
    }
    protected function columns(): array
    {
        $row=fn(Server $s)=>$this->row($s);
        // One line per account. The reason, a missing balance's cause, a low balance and missing costs go in Note, each with its step.
        return [
            Column::value('name','Account',fn(Server $s): Value=>Value::fact(Fact::names([(string)$s->name])))->searchable()->demoOf(fn(Server $s)=>$row($s)['account']),
            Column::text('registrar','Registrar',fn(Server $s): ?string=>$row($s)['account']?TldFacts::registrar($row($s)['account']->registrar_key):null),
            Column::text('mode','Mode',fn(Server $s): ?string=>$row($s)['connected']?RegistrarFacts::mode($row($s)['account']):null),
            Column::value('status','Status',fn(Server $s): Value=>Value::state(RegistrarFacts::state($row($s)['status']),Reader::Staff,null,'registrar-'.$s->id)),
            Column::note('note','Note',fn(Server $s): ?string=>$row($s)['note']['text'])->actWhen(fn(Server $s): bool=>$row($s)['note']['act']),
            Column::value('balance','Balance',fn(Server $s): ?Value=>($b=$row($s)['balance'])===null?null:(self::money($b['value'])??Value::state($b['missing']??State::CouldntRead,Reader::Staff,null,'balance-'.$s->id)))->end(),
            Column::value('due','Due in 30 days',fn(Server $s): ?Value=>self::money($row($s)['balance']['due']??null))->end(),
            Column::value('checked','Balance checked',fn(Server $s): ?Value=>($c=$row($s)['balance']['checked']??null)&&($t=strtotime((string)$c))?Value::fact(Fact::date($t,Reader::Staff)):null)->optional(),
            Column::value('domains','Domains',fn(Server $s): Value=>Value::fact(Fact::count((int)$row($s)['domains'])))->end()->optional(),
        ];
    }
    protected function filters(): array
    {
        return [
            Filter::select('registrar','Registrar',Account::query()->distinct()->pluck('registrar_key')->mapWithKeys(fn(string $key)=>[$key=>TldFacts::registrar($key)])->all())
                ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $key)=>$q->whereIn('id',Account::where('registrar_key',$key)->select('server_id')))),
            Filter::select('mode','Mode',['live'=>'Live','test'=>'Test'])
                ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $mode)=>$q->whereIn('id',Account::where('environment',$mode==='live'?'=':'!=','live')->select('server_id')))),
            // Status is worked out per account, so this one reads the rows.
            Filter::select('status','Status',self::accounts()->get()->map(fn(Server $s)=>$this->row($s)['status']['label'])->unique()->sort()->mapWithKeys(fn(string $label)=>[$label=>$label])->all())
                ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $label)=>$q->whereIn('id',self::accounts()->get()->filter(fn(Server $s)=>$this->row($s)['status']['label']===$label)->modelKeys()))),
        ];
    }
    protected function recordActions(): array
    {
        $row=fn(Server $s)=>$this->row($s);
        return [
            Action::make('add_funds')->label('Add funds')->link()->icon('heroicon-m-arrow-top-right-on-square')->iconPosition(IconPosition::After)
                ->visible(fn(Server $record)=>$row($record)['balance']!==null)->url(fn(Server $record)=>$row($record)['balance']['funds']??null)->openUrlInNewTab()
                ->extraAttributes(['rel'=>'noopener noreferrer']),
            Action::make('connect')->label('Connect')->link()->visible(fn(Server $record)=>$row($record)['account']===null)
                ->url(fn(Server $record)=>RegistrarAccount::getUrl(['account'=>$record->id])),
        ];
    }
    /** @return array{account:?Account,status:array,connected:bool,domains:int,demo:bool,balance:?array,note:array{text:?string,act:bool}} */
    private function row(Server $s): array
    {
        if (isset($this->rows[$s->id])) { return $this->rows[$s->id]; }
        $a=Account::where('server_id',$s->id)->first();$status=RegistrarFacts::status($s,$a);$connected=RegistrarFacts::connected($a,$status);
        $balance=$connected?RegistrarFacts::balance($a):null;
        return $this->rows[$s->id]=['account'=>$a,'status'=>$status,'connected'=>$connected,'domains'=>Domain::where('server_id',$s->id)->count(),
            'demo'=>$a!==null&&DemoData::is($a),'balance'=>$balance,'note'=>self::note($status['reason'],$balance,$a,\Paymenter\Extensions\Servers\Domains\Admin\Support\PaidUncertainty::accountStep((int)$s->id),$status['act']??false)];
    }
    /** The account's Note: why it is in this state, then any job on its money, each with its step. @return array{text:?string,act:bool} */
    private static function note(?string $reason,?array $b,?Account $account,?string $held=null,bool $act=false): array
    {
        // Suppress the repeated reason only when the saved settings cannot load. A stale worker turn can cause the same
        // status while the balance is missing for a separate reason, so staff still need both causes then.
        if ($reason===AccountStatus::STATES['configuration_unavailable'][1]&&$b!==null&&$b['value']===null&&$b['cause']!==null
            &&$account!==null&&!app(AccountBindings::class)->configurationAvailable((int)$account->server_id)) { $reason=null; }
        // Requests under review hold the account, so the row says so first, in amber, as its banner does.
        $jobs=$b===null?($held?[$held]:[]):array_filter([$held,
            $b['value']===null?$b['cause']:null,
            $b['value']!==null&&$b['low']?'Low balance. Add funds.':null,
        ]);
        // A renewal waiting on our price read is told, not a job: it fills in by itself.
        $gap=$b===null?null:RegistrarFacts::dueGap($account,$b);
        if ($gap!==null&&$gap['act']) { $jobs[]=$gap['text']; }
        $parts=array_map(StaffDomainStatus::sentence(...),array_filter([$reason,...$jobs,$gap!==null&&!$gap['act']?$gap['text']:null]));
        // A reason staff must act on (an account that sells nothing) is a job too.
        return ['text'=>$parts===[]?null:implode(' ',$parts),'act'=>$act||$jobs!==[]];
    }
    private static function accounts(): Builder { return Server::query()->where('extension','Domains'); }
    private static function resourceUrl(string $resource,string $page): ?string
    { try { return class_exists($resource)?$resource::getUrl($page):null; } catch (\Throwable) { return null; } }
}
