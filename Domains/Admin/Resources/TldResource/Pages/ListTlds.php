<?php
// Enom 444f31c ListTlds layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{CatalogueSalesNotice,EmptyTable,Sheet,SettingsActions,Staff,StaffTabs,TldFacts,TldTerms};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,Filter,RecordListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Missing,Reader,State};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Domains > TLDs & pricing, a List page: each TLD with its registrar account, prices and whether it is for sale. */
class ListTlds extends RecordListPage
{
    protected static string $resource=TldResource::class;
    /** Request-scoped facts of the visible page and this reader's grants; protected, so never part of the Livewire snapshot. */
    protected ?array $visible=null;
    protected array $demo=[];
    protected ?\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants $read=null;
    public function getSubNavigation(): array{return StaffTabs::tlds('All TLDs');}
    public function getBreadcrumbs(): array{return [];}
    protected function head(): Head{$this->grants();return Head::make('TLDs & pricing',Reader::Staff);}
    protected function actions(): array
    {
        $servers=[];
        foreach(Account::query()->whereIn('server_id',\App\Models\Server::where('extension','Domains')->where('type','server')->select('id'))->orderBy('server_id')->get() as $a){
            if(SettingsActions::syncOffered($a)){$servers[]=(int)$a->server_id;}
        }
        return array_map(fn(int $s)=>SettingsActions::syncAll($s,count($servers)>1),$servers);
    }
    protected function notices(): array
    {
        $sections=CatalogueSalesNotice::sections();
        $failures=TldResource::syncFailures();
        if($failures){
            $count=count($failures);
            $text='Price sync failed for '.$count.' registrar '.($count===1?'account':'accounts').'. '
                .implode(' ',array_map(static fn(array $f)=>$f['account'].': '.$f['cause'].'.',$failures))
                .' New sales stop when registrar costs are out of date. See why on the Registrar account page.';
            $open=RegistrarSetup::canAccess()?Sheet::link('open_registrars','Open Registrars',RegistrarSetup::getUrl()):null;
            $sections[]=Section::make('Price sync',[Row::notice('price-sync',Value::state(State::Failed,Reader::Staff,$text),$open)]);
        }
        return $sections;
    }
    private function grants(): \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants{return $this->read??=Staff::requireView();}
    /** The facts of every TLD on the page, read in one batch so extra rows add no queries. */
    private function fact(Tld $t): array
    {
        $records=$this->getTableRecords();
        if(($this->visible['records']??null)!==$records){
            $page=collect(method_exists($records,'items')?$records->items():$records->all())->filter(fn($r)=>$r instanceof Tld);
            $this->visible=['records'=>$records,'facts'=>TldFacts::page($page,$this->grants())];
        }
        return $this->visible['facts'][$t->id]??TldFacts::page(collect([$t]),$this->grants())[$t->id];
    }
    private function isDemo(Tld $t): ?Account{return $this->demo[$t->server_id]??=Account::where('server_id',$t->server_id)->first();}
    /** A price cell: the amount, or the one word for why there is none, with its cause on the line below. */
    private function price(Tld $t,string $field): Value
    {
        $fact=$this->fact($t);$state=TldFacts::priceState($fact,'renew',$field);$staff=Reader::Staff;$id='tld-'.$t->id;
        // The Note column already says No registrar selected, and a price sync can't fix it; the price cells show only the price.
        $cause=str_starts_with((string)$state['cause'],TldFacts::NO_SELLER)&&str_starts_with((string)($fact['sales']['reason']??''),TldFacts::NO_SELLER)?null:$state['cause'];
        $value=$state['value'];
        if(is_string($value)&&preg_match('/^\$[\d,]+(?:\.\d+)?$/D',$value)===1){
            $money=Fact::money(str_replace(['$',','],'',$value));
            // A price under its cost reads Below cost, amber, as on the TLD page.
            if($cause===TldFacts::BELOW_COST){return Value::state(State::BelowCost,$staff,$money->text.'.',$id);}
            return Value::fact($money,$state['tone']==='danger'?$cause:null);
        }
        return match(true){
            $state['missing']&&$value===Missing::LABEL=>Value::state($field==='cost'?State::NotReadYet:State::NotSet,$staff,$cause,$id),
            $value===Missing::LABEL=>Value::state(State::NeedsReview,$staff,$cause,$id),
            $value==='Not for sale'=>Value::state(State::NotForSale,$staff,$cause,$id),
            default=>Value::state(State::NotSupported,$staff,is_string($value)?$value:$cause,$id),
        };
    }
    protected function columns(): array
    {
        $grants=$this->grants();$changeSeller=$grants->policy&&Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('seller'));
        return [
            Column::value('tld','TLD',fn(Tld $t): Value=>Value::fact(Fact::tlds([$t->tld])))->searchable()->sortable(),
            Column::value('account','Registrar account',fn(Tld $t): Value=>Value::fact(Fact::names([(string)$t->server->name]),
                TldFacts::registrar($t->registrar_key).', '.strtolower($this->fact($t)['pricing']).($this->fact($t)['seller']===(int)$t->server_id?', new orders':'')))
                ->demoOf(fn(Tld $t)=>$this->isDemo($t)),
            Column::value('renewal','Renewal price',fn(Tld $t): Value=>$this->price($t,'retail'))->end(),
            Column::value('cost','Cost',fn(Tld $t): Value=>$this->price($t,'cost'))->end()->visibleWhen(fn()=>$this->grants()->costs),
            Column::value('holders','Domains',fn(Tld $t): Value=>Value::fact(Fact::count((int)$this->fact($t)['holders']))),
            Column::value('status','Status',fn(Tld $t): Value=>Value::state($this->fact($t)['sales']['label']==='For sale'?State::ForSale:State::NotForSale,Reader::Staff,null,'tld-'.$t->id)),
            Column::note('note','Note',fn(Tld $t): ?string=>($reason=$this->fact($t)['sales']['reason'])?trim($reason.'. '.TldFacts::fixText($reason,$changeSeller)):null),
        ];
    }
    protected function filters(): array
    {
        return [
            Filter::select('registrar_key','Registrar',['enom'=>'Enom','namesilo'=>'NameSilo']),
            Filter::select('server_id','Registrar account',\App\Models\Server::where('extension','Domains')->pluck('name','id')->all()),
            Filter::toggle('needs_attention','Terms need attention',fn(Builder $query): Builder=>$query->whereIn('domains_tlds.id',TldTerms::summary(
                filter_var($this->getTableFilterState('server_id')['value']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null)['attention'])),
        ];
    }
    protected function defaultSort(): ?array{return ['tld','asc'];}
    protected function searchLabel(): ?string{return 'Search TLD';}
    private function narrowed(): bool{return filled($this->getTableSearch())||$this->getTable()->isFiltered();}
    protected function emptyLine(): array
    {
        $attention=(bool)($this->tableFilters['needs_attention']['isActive']??false)&&blank($this->tableSearch);
        return ['No TLDs yet','Choose what you sell on a registrar account.',$attention?'No TLD terms need attention':'No TLDs match'];
    }
    protected function emptyAction(): ?Action
    {return Action::make('choose_what_you_sell')->label('Choose what you sell')->visible(fn()=>!$this->narrowed())->url(fn()=>WhatYouSell::getUrl());}
}
