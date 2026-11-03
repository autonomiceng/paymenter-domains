<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{SellRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,EndingChoices,PriceList,PricingRule,PricingRules,SellRule,SellTableSchema};
/** The rows What you sell shows and what each one says. One request only: a page reads an account once, not per cell. */
final class SellTable
{
    private const ESTIMATE='Estimate from the saved cost and pricing rule.';
    private const COLUMNS=['id','server_id','account_fingerprint','tld','choice','longest','transfer','register_cost','renew_cost','transfer_cost','high_cost','prices','read_at'];
    private array $accepted=[];
    private array $rules=[];
    private ?array $fee=null;
    private ?array $defaultRule=null;
    private ?array $saleFacts=null;
    private array $rowTlds=[];
    private array $rowNumbers=[];
    private ?array $savedRead=null;
    private ?array $savedRows=null;
    private array $filtered=[];
    private array $ruleSaved=[];
    private array $legacy=[];
    /** @param array<int,AccountBinding> $bindings by server
     * @param \Closure():array<SellRow> $page the rows on screen
     * @param bool $projected the page did not run SellRule::ensure, so rows show as ensure would save them, with no write */
    public function __construct(private readonly array $bindings,private readonly int $years,private readonly bool $costs,private readonly \Closure $page,private readonly bool $projected=false) {}
    /** The registrar's list plus saved TLDs it no longer has, so a price with no sales row still shows.
     * Those rows stay read-only and carry the negated TLD id: a write here would add a sales row. Hidden TLDs show only when already saved.
     * The cast gives the id number affinity, so SQLite matches Filament's string keys. */
    public function rows(?string $focus): Builder
    {
        if($this->bindings===[]){return SellRow::query()->whereRaw('1=0');}
        $table=SellTableSchema::ROWS;$hidden=EndingChoices::hiddenList();
        $listed=DB::table($table)->select(self::COLUMNS)->selectRaw('1 as listed')->where(function($any)use($hidden,$table){
            foreach($this->bindings as $server=>$binding){
                $any->orWhere(fn($one)=>$one->where(['server_id'=>$server,'account_fingerprint'=>$binding->fingerprint])->where(fn($shown)=>$shown->whereNotIn('tld',$hidden)->orWhereIn('tld',array_keys($this->accepted($server)))
                    ->orWhereExists(fn($saved)=>$saved->selectRaw('1')->from('domains_tlds as t')->whereColumn('t.server_id',$table.'.server_id')->whereColumn('t.account_fingerprint',$table.'.account_fingerprint')->whereColumn('t.tld',$table.'.tld'))));
            }
        });
        $saved=TldFacts::bound(DB::table('domains_tlds'))->whereIn('server_id',array_keys($this->bindings))
            ->whereNotExists(fn($row)=>$row->selectRaw('1')->from($table.' as s')->whereColumn('s.server_id','domains_tlds.server_id')->whereColumn('s.account_fingerprint','domains_tlds.account_fingerprint')->whereColumn('s.tld','domains_tlds.tld'))
            ->selectRaw('cast(-id as signed) as id,server_id,account_fingerprint,tld,null as choice,null as longest,null as transfer,null as register_cost,null as renew_cost,null as transfer_cost,null as high_cost,null as prices,null as read_at,0 as listed');
        $unsaved=$this->unsaved();
        foreach($unsaved as $server=>$tlds){$saved->where(fn($other)=>$other->where('server_id','!=',$server)->orWhereNotIn('tld',$tlds));}
        $all=$listed->unionAll($saved);
        // Chunks keep each compound select under SQLite's limit.
        foreach(array_chunk(array_merge(...array_map(fn(int $server,array $tlds)=>array_map(fn(string $tld)=>[$server,$tld],$tlds),array_keys($unsaved),array_values($unsaved))),200) as $chunk){
            $part=null;
            foreach($chunk as [$server,$tld]){
                $one=DB::query()->selectRaw('cast(? as signed) as id,? as server_id,? as account_fingerprint,? as tld,null as choice,null as longest,null as transfer,null as register_cost,null as renew_cost,null as transfer_cost,null as high_cost,null as prices,null as read_at,1 as listed',
                    [-($server*4294967296+crc32($tld)),$server,$this->bindings[$server]->fingerprint,$tld]);
                $part=$part===null?$one:$part->unionAll($one);
            }
            $all->unionAll(DB::query()->fromSub($part,'unsaved')->select('*'));
        }
        return SellRow::query()->fromSub($all,$table)->when($focus!==null,fn($q)=>$q->where('tld',$focus));
    }
    /** @return array<int,list<string>> accepted TLDs with no sales row yet, by server. Only a projected read shows them; one account's view saves them first. */
    private function unsaved(): array
    {
        if(!$this->projected){return [];}
        $out=[];
        foreach($this->bindings as $server=>$binding){
            $accepted=array_map('strval',array_keys($this->accepted($server)));
            if($accepted===[]){continue;}
            $have=SellRule::rows($binding)->whereIn('tld',$accepted)->pluck('tld')->all();
            if(($missing=array_values(array_diff($accepted,$have)))!==[]){$out[$server]=$missing;}
        }
        return $out;
    }
    /** A row as SellRule::ensure would save it: before any rule is saved, a TLD the account sells reads as Sell with its accepted terms. */
    private function effective(SellRow $row): SellRow
    {
        if(!$this->projected||$row->choice!==null){return $row;}
        $server=(int)$row->server_id;$sold=$this->accepted($server)[$row->tld]??null;
        if($sold===null||$sold['register']===[]||($this->ruleSaved[$server]??=SellRule::saved($this->bindings[$server])!==null)){return $row;}
        return $this->legacy[self::key($row)]??=(clone $row)->forceFill(['choice'=>'sell','longest'=>max($sold['register']),'transfer'=>$sold['transfer']!==[]]);
    }
    /** @return array<string,array> what the account sells now */
    public function accepted(int $server): array
    {
        if(isset($this->accepted[$server])){return $this->accepted[$server];}
        try{return $this->accepted[$server]=CatalogueAcceptances::load($this->bindings[$server])->endings;}catch(\DomainException){return $this->accepted[$server]=[];}
    }
    public function rule(int $server): array
    {
        if(isset($this->rules[$server])){return $this->rules[$server];}
        $binding=$this->bindings[$server];
        try{$accepted=CatalogueAcceptances::load($binding);}catch(\DomainException){$accepted=null;}
        return $this->rules[$server]=SellRule::read($binding,$accepted);
    }
    /** Why this row can't be saved, or null when it can. */
    public function blocked(SellRow $row): ?string
    {
        if(!$row->listed){return "The registrar doesn't list this TLD.";}
        return EndingChoices::hidden($row->tld)&&!isset($this->accepted((int)$row->server_id)[$row->tld])?'This TLD is hidden.':null;
    }
    /** @return array{label:string,tone:string,next?:string,open?:bool} */
    public function status(SellRow $row): array
    {
        $row=$this->effective($row);
        if(!$row->listed){return ['label'=>'Not for sale','tone'=>'gray'];}
        $server=(int)$row->server_id;$sold=$this->accepted($server)[$row->tld]??null;$rule=$this->rule($server);
        if($sold!==null&&$sold['register']===[]){return ['label'=>$sold['transfer']===[]?'Renewals only':'Renewals and transfers only','tone'=>'gray'];}
        if($sold!==null&&SellRule::decide($row,$rule)==='limit'){
            $sales=$this->sales($row);
            return ['label'=>$sales['label']??'Waiting for prices','tone'=>$sales['tone']??'warning',
                'next'=>$this->costs?'Over the cost limit. The next sync stops new sales.':'The next sync updates sales.'];
        }
        $waiting=['label'=>'Waiting for prices','tone'=>'warning'];
        return match(SellRule::decide($row,$rule)){
            'skipped'=>['label'=>'Not for sale','tone'=>'gray'],
            'limit'=>['label'=>$this->costs?'Over the cost limit':'Not for sale','tone'=>'gray'],
            'waiting'=>$waiting,
            default=>$sold===null?($row->high_cost===null?$waiting:['label'=>'Waiting for prices','tone'=>'warning','next'=>'The next price sync adds it. Check orders under Registrars'])
                :(($sales=$this->sales($row))===null?$waiting:['label'=>$sales['label'],'tone'=>$sales['tone']??'gray',...(($sales['reason']??null)===null?[]:['next'=>$sales['reason'].'. Open TLDs & pricing','open'=>true])]),
        };
    }
    /** Sale state for the rows on screen, read once. Null when no prices are saved for it yet. */
    private function sales(SellRow $row): ?array
    {
        $facts=$this->facts()[self::key($row)]??null;
        return $facts===null||$facts['prices']===[]?null:$facts['sales'];
    }
    private function facts(): array
    {
        if($this->saleFacts!==null){return $this->saleFacts;}
        $tlds=Tld::whereIn('server_id',array_keys($this->bindings))->whereIn('tld',array_map(fn(SellRow $row)=>$row->tld,($this->page)()))->get()
            ->filter(fn(Tld $t)=>$t->account_fingerprint===$this->bindings[(int)$t->server_id]->fingerprint);
        $this->rowTlds=$tlds->keyBy(fn(Tld $t)=>$t->server_id.':'.$t->tld)->all();$page=TldFacts::page($tlds);
        return $this->saleFacts=$tlds->mapWithKeys(fn(Tld $t)=>[$t->server_id.':'.$t->tld=>$page[$t->id]])->all();
    }
    /** The saved TLD behind a row, for its own page. A row nothing is saved for yet has none. */
    public function tldId(SellRow $row): ?int
    {
        $this->facts();$tld=$this->rowTlds[self::key($row)]??null;
        return $tld===null?null:(int)$tld->id;
    }
    /** @return array{0:?string,1:?string,2?:?string} estimated price, profit and why the price is a loss, from the saved rule. Only for a row with no saved price. */
    private function numbers(SellRow $row): array
    {
        $key=self::key($row);
        if(isset($this->rowNumbers[$key])){return $this->rowNumbers[$key];}
        try{
            $fee=$this->fee??=CardFee::read();$cost=(string)$row->register_cost;
            $tld=$this->rowTlds[$key]??null;
            $rule=$tld&&!$tld->use_default_rule?(new PricingRules)->rule($tld,false,$fee):($this->defaultRule??=(new PricingRules)->defaultRule(false,$fee));
            $retail=PricingRule::price($cost,$rule,'register');
            $profit=PriceList::profit(CardFee::profit($retail,$cost,$fee));
            return $this->rowNumbers[$key]=[$retail,$profit,self::loss(!PricingRule::covers($retail,$cost),str_starts_with($profit,'-'))];
        }catch(\Throwable){return [null,null];}
    }
    /** The saved prices for this view. Staff without costs never read them. */
    public function saved(): array
    {
        if($this->savedRead!==null){return $this->savedRead;}
        return $this->savedRead=$this->costs&&$this->bindings!==[]?app(PriceList::class)->read($this->years,'all',$this->scope()):['rows'=>[],'skipped'=>[],'warnings'=>[],'explanations'=>[]];
    }
    public function scope(): ?int {return count($this->bindings)===1?array_key_first($this->bindings):null;}
    private function savedRow(SellRow $row): ?array
    {
        $this->savedRows??=collect([...$this->saved()['rows'],...$this->saved()['skipped']])->keyBy(fn(array $r)=>$r['server_id'].':'.ltrim($r['tld'],'.'))->all();
        return $this->savedRows[self::key($row)]??null;
    }
    private function kept(string $filter): array {return $this->filtered[$filter]??=app(PriceList::class)->read($this->years,$filter,$this->scope());}
    /** The formulas behind the table's rows on every page: search and filters narrow these along with the table.
     * @param list<string> $held server:tld keys the table holds */
    public function explanations(array $held): array
    {
        $keep=array_flip($held);
        return PriceList::explain(array_filter($this->saved()['rows'],static fn(array $row):bool=>isset($keep[$row['server_id'].':'.ltrim($row['tld'],'.')])));
    }
    /** Narrows the table to the TLDs a price filter keeps. The same read as the CSV, so both list the same rows. */
    public function only(Builder $query,string $filter): Builder
    {
        $kept=collect($this->kept($filter)['rows'])->groupBy('server_id');
        return $kept->isEmpty()?$query->whereRaw('1=0'):$query->where(function($any)use($kept){
            foreach($kept as $server=>$rows){$any->orWhere(fn($one)=>$one->where('server_id',$server)->whereIn('tld',$rows->map(fn(array $r)=>ltrim($r['tld'],'.'))->all()));}
        });
    }
    /** Left-out rows are why a price filter is empty only when they are all it had to read: the filter's own rows, or every row when it filters on amounts. */
    public function leftOutExplains(string $filter): bool
    {
        return $this->kept($filter)['skipped']!==[]&&(in_array($filter,['default','own'],true)||$this->saved()['rows']===[]);
    }
    public function pricing(SellRow $row): string {return $this->savedRow($row)['pricing']??'Default';}
    public function terms(SellRow $row): string
    {
        $row=$this->effective($row);$longest=$row->longest??$this->rule((int)$row->server_id)['years'];
        return 'Up to '.$longest.' '.($longest===1?'year':'years').($row->longest===null?', default':'');
    }
    /** One money cell. A saved price wins, even one a failed publish left on an old rule: its loss stays red and Below cost reads this same price.
     * Register at 1 year shows the live cost and an estimate only when nothing is saved.
     * @return array{text:?string,tone:?string,hint:?string,mark:bool} */
    public function cell(SellRow $row,string $action,string $field): array
    {
        $saved=$this->savedRow($row);
        if(isset($saved['cause'])){return self::cellOf('Left out','gray',"Its pricing can't be read. See the line above the rows.");}
        $prices=$saved['prices'][$action]??null;
        if($action==='register'&&$this->years===1&&$row->listed){
            if(($prices['price']??null)===null&&$row->register_cost!==null){return $this->registerCell($row,$field);}
            if(($prices[$field]??null)===null&&$this->selling($row,'register')!=='sold'){return self::cellOf($field==='cost'?'No cost yet':null,'gray');}
        }
        return $this->savedCell($prices,$field,$this->selling($row,$action));
    }
    private function registerCell(SellRow $row,string $field): array
    {
        [$price,$profit,$loss]=$this->numbers($row)+[2=>null];
        if($field==='cost'){return self::cellOf(Str::after((string)TldFacts::money((string)$row->register_cost,4),'USD '),null,$row->read_at===null?null:'Prices from '.self::time((int)$row->read_at));}
        if($price===null){return self::cellOf(null);}
        if($field==='price'){return self::cellOf($price,'gray',self::ESTIMATE);}
        return self::cellOf($profit,$loss!==null?'danger':'gray',$loss??self::ESTIMATE);
    }
    /** Saved prices win: a price stays on screen even if its term has stopped selling. */
    private function savedCell(?array $saved,string $field,string $sold): array
    {
        $value=$saved[$field]??null;$loss=self::loss($saved['below_cost']??false,$saved['loses_money']??false);
        if($value!==null){return $field==='profit'?self::cellOf(PriceList::profit($value),$loss===null?null:'danger',$loss):self::cellOf($field==='cost'?PriceList::cost($value):$value);}
        if($sold==='off'||($saved['status']??null)===PriceList::TRANSFERS_OFF){return self::cellOf('Off','gray');}
        if($field==='profit'){return self::cellOf(null);}
        if($sold==='filling'){return self::cellOf('Missing','gray','The next price sync adds it.');}
        return self::missing($field==='cost'?'Use Sync prices.':'To add one, open the TLD page and use Edit prices.');
    }
    /** sold: an accepted term. filling: the next sync accepts it. off: not sold this long, or at all. */
    private function selling(SellRow $row,string $action): string
    {
        $row=$this->effective($row);
        if(!$row->listed){return 'off';}
        $server=(int)$row->server_id;$accepted=$this->accepted($server)[$row->tld]??null;
        if($accepted!==null){return in_array($this->years,$accepted[$action],true)?'sold':'off';}
        $rule=$this->rule($server);
        if($action==='transfer'&&!($row->transfer??$rule['transfers'])){return 'off';}
        if($this->years>($action==='register'?($row->longest??$rule['years']):1)){return 'off';}
        return in_array(SellRule::decide($row,$rule),['sell','waiting'],true)?'filling':'off';
    }
    private static function cellOf(?string $text,?string $tone=null,?string $hint=null): array {return ['text'=>$text,'tone'=>$tone,'hint'=>$hint,'mark'=>false];}
    private static function missing(string $step): array {return ['text'=>'Missing','tone'=>'danger','hint'=>$step,'mark'=>true];}
    private static function loss(bool $below,bool $loses): ?string {return $below?'This price is below registrar cost.':($loses?'This price loses money after the card fee.':null);}
    public static function time(int $at): string {return gmdate('M j, Y H:i',$at).' UTC';}
    public static function key(SellRow $row): string {return $row->server_id.':'.$row->tld;}
}
