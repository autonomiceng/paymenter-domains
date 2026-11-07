<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{OfferRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{EndingChoices,PriceList};
/** The rows What you sell shows and what each one says. One request only: a page reads an account once, not per cell. */
final class SellTable
{
    private array $rules=[];
    private array $steps=[];
    private ?array $saleFacts=null;
    private array $rowTlds=[];
    private ?array $savedRead=null;
    private ?array $savedRows=null;
    private ?array $hiddenWarnings=null;
    private array $filtered=[];
    private ?StaffGrants $grants=null;
    /** Account and settings reads the next-step hints share across Register, Renew and Transfer in this build. */
    private array $hintReads=[];
    /** @param array<int,AccountBinding> $bindings by server
     * @param \Closure():array<OfferRow> $page the rows on screen
     * @param ?\Closure():array<OfferRow> $filteredRows all rows kept by search and filters */
    public function __construct(private readonly array $bindings,private readonly int $years,private readonly bool $costs,private readonly \Closure $page,private readonly bool $projected=false,private readonly ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $catalogue=null,private readonly ?\Closure $filteredRows=null) {}
    /** The registrar's list plus saved TLDs it no longer has, so a price with no sales row still shows.
     * Those rows stay read-only and carry the negated TLD id: a write here would add a sales row. Hidden TLDs show only when already saved.
     * The cast gives the id number affinity, so SQLite matches Filament's string keys. */
    private function source(): \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue
    { return $this->catalogue ?? \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()
        ?? throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }
    public function rows(?string $focus): Builder { return $this->source()->rows($this->bindings,$focus); }
    /** The viewer's rights, read once for this table build: a cell or a saved-price read never asks again. */
    private function grants(): StaffGrants { return $this->grants??=Staff::requireView(); }
    /** @return array<int,list<string>> accepted TLDs with no sales row yet, by server. Only a projected read shows them; one account's view saves them first. */

    /** Derived active offer row; never persisted as a second catalogue. */

    /** @return array<string,array> what the account sells now */

    public function rule(int $server): array { return $this->rules[$server]??=$this->source()->rule($server); }
    /** Why this row can't be saved, or null when it can. */
    public function blocked(OfferRow $row): ?string { return EndingChoices::hidden($row->tld)?'This TLD is hidden.':null; }
    /** @return array{label:string,tone:string,next?:string,open?:?string} */
    public function status(OfferRow $row): array
    {
        $facts=$this->facts()[self::key($row)]??[];
        if(!($facts['acquisition']['register']??false)&&($facts['acquisition']['transfer']??false)){return ['label'=>'Renewals and transfers only','tone'=>'gray'];}
        $s=$facts['sales']??['label'=>'Not for sale','tone'=>'gray','reason'=>TldFacts::NEEDS_SYNC];
        if($s['reason']===null){return ['label'=>$s['label'],'tone'=>$s['tone']];}
        if(!Staff::requireView()->costs){return ['label'=>$s['label'],'tone'=>$s['tone'],'next'=>$s['reason'],'open'=>null];}
        $action=in_array('register',$facts['intent']['stops']??[],true)?'transfer':'register';
        $step=$this->nextStep($row,$action);
        return ['label'=>$step['state']==='checking'?'Checking':$s['label'],'tone'=>$s['tone'],'next'=>$step['next']?:$s['reason'],'open'=>$step['open']];
    }
    /** Sale state for the rows on screen, read once. Null when no prices are saved for it yet. */

    private function facts(): array
    {
        if($this->saleFacts!==null){return $this->saleFacts;}
        $tlds=$this->tlds(($this->page)());
        $this->rowTlds=$tlds->keyBy(fn(Tld $t)=>$t->server_id.':'.$t->tld)->all();$page=$this->source()->page($tlds);
        return $this->saleFacts=$tlds->mapWithKeys(fn(Tld $t)=>[$t->server_id.':'.$t->tld=>$page[$t->id]])->all();
    }
    /** One binding-scoped identity read for the supplied account/TLD pairs. */
    private function tlds(array $rows,array $columns=['*']): \Illuminate\Database\Eloquent\Collection
    {
        $pairs=collect($rows)->groupBy('server_id');
        return Tld::where(function($q)use($pairs):void {
            $q->whereRaw('1=0');
            foreach($pairs as $server=>$rows){if(isset($this->bindings[$server])){$q->orWhere(fn($one)=>$one->where('server_id',$server)
                ->where('account_fingerprint',$this->bindings[$server]->fingerprint)->whereIn('tld',$rows->pluck('tld')));}}
        })->get($columns);
    }
    /** The saved TLD behind a row, for its own page. A row nothing is saved for yet has none. */
    public function tldId(OfferRow $row): ?int
    {
        $this->facts();$tld=$this->rowTlds[self::key($row)]??null;
        return $tld===null?null:(int)$tld->id;
    }
    /** @return array{0:?string,1:?string,2?:?string} estimated price, profit and why the price is a loss, from the saved rule. Only for a row with no saved price. */

    /** The saved prices for this view. Staff without costs never read them. */
    public function saved(): array
    {
        if(!$this->grants()->costs){return ['rows'=>[],'skipped'=>[],'warnings'=>[],'explanations'=>[]];}
        if($this->savedRead!==null){return $this->savedRead;}
        $this->facts();
        return $this->savedRead=$this->costs?$this->source()->matrix(collect($this->rowTlds),$this->years):['rows'=>[],'skipped'=>[],'warnings'=>[],'explanations'=>[]];
    }
    /** Name omitted TLDs on other filtered pages without loading their offer/price snapshots. */
    public function hiddenWarnings(): array
    {
        if(!$this->grants()->costs||!$this->costs||$this->filteredRows===null){return [];}
        if($this->hiddenWarnings!==null){return $this->hiddenWarnings;}
        $this->facts();$hidden=$this->tlds(($this->filteredRows)(),['id','server_id','tld','catalogue_intent','use_default_rule','pricing_mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer'])->reject(fn(Tld $t)=>isset($this->rowTlds[$t->server_id.':'.$t->tld]));
        $omitted=array_flip($this->source()->omissions($hidden));
        return $this->hiddenWarnings=$hidden->filter(fn(Tld $t)=>isset($omitted[$t->server_id.':'.$t->tld]))->map(fn(Tld $t)=>['id'=>(int)$t->id,'tld'=>'.'.$t->tld])->values()->all();
    }
    public function scope(): ?int {return count($this->bindings)===1?array_key_first($this->bindings):null;}
    private function savedRow(OfferRow $row): ?array
    {
        $this->savedRows??=collect([...$this->saved()['rows'],...$this->saved()['skipped']])->keyBy(fn(array $r)=>$r['server_id'].':'.ltrim($r['tld'],'.'))->all();
        return $this->savedRows[self::key($row)]??null;
    }
    private function kept(string $filter): array {return $this->filtered[$filter]??=app(PriceList::class)->read($this->years,$filter,$this->scope());}
    /** The formulas behind the current page's rows: search and filters narrow these along with the table.
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
        return $this->kept($filter)['skipped']!==[]&&(in_array($filter,['default','own'],true)||$this->kept('all')['rows']===[]);
    }
    public function pricing(OfferRow $row): string {return $this->savedRow($row)['pricing']??'Default';}
    public function terms(OfferRow $row): string
    {
        $this->facts();$longest=$this->saleFacts[self::key($row)]['intent']['longest']??null;
        $years=$longest??$this->rule((int)$row->server_id)['years'];
        return 'Up to '.$years.' '.($years===1?'year':'years').($longest===null?', default':'');
    }
    /** One money cell. A saved price wins, even one a failed publish left on an old rule: its loss stays red and Below cost reads this same price.
     * Register at 1 year shows the live cost and an estimate only when nothing is saved.
     * @return array{text:?string,tone:?string,hint:?string,mark:bool} */
    public function cell(OfferRow $row,string $action,string $field): array
    {
        if(!$this->grants()->costs){return self::cellOf(null);}
        $saved=$this->savedRow($row);
        if(isset($saved['cause'])){return self::cellOf('Left out','gray',"Its pricing can't be read. See the alert above.");}
        $sold=$this->sold($row,$action);
        $step=$field!=='profit'&&$sold!=='off'&&($saved['prices'][$action][$field]??null)===null?$this->nextStep($row,$action):[];
        if($field==='cost'&&($saved['prices'][$action]['cost']??null)===null&&$this->retainedRenewal($row,$action)
            &&($step['state']??null)!=='checking'&&($step['open']??null)!=='account'){
            $step=['state'=>'missing','next'=>'Use Sync prices.','open'=>null];
        }
        return $this->savedCell($saved['prices'][$action]??null,$field,$sold,$step);
    }

    /** Saved prices win: a price stays on screen even if its term has stopped selling. */
    private function savedCell(?array $saved,string $field,string $sold,array $step): array
    {
        $value=$saved[$field]??null;$loss=self::loss($saved['below_cost']??false,$saved['loses_money']??false);
        if($value!==null){return $field==='profit'?self::cellOf(PriceList::profit($value),$loss===null?null:'danger',$loss):self::cellOf($field==='cost'?PriceList::cost($value):$value);}
        if($sold==='off'||($saved['status']??null)===PriceList::TRANSFERS_OFF){return self::cellOf('Off','gray');}
        if($field==='profit'){return self::cellOf(null);}
        if(($step['state']??null)==='off'){return [...self::cellOf('Off','gray'),...$step];}
        if(($step['state']??null)==='checking'){return [...self::cellOf('Checking','gray'),...$step];}
        return [...self::missing($step['next']?:'Open Edit prices on the TLD page.'),...$step];
    }
    private function nextStep(OfferRow $row,string $action): array
    {
        $key=self::key($row);$this->facts();
        $this->steps[$action]??=$this->source()->nextSteps(collect($this->rowTlds),$this->facts(),$action,$this->years,$this->hintReads);
        return $this->steps[$action][$key];
    }
    /** Sale intent controls absent cells; saved values still win in savedCell(). */
    private function sold(OfferRow $row,string $action): string
    {
        $facts=$this->facts()[self::key($row)]??[];$intent=$facts['intent']??[];$defaults=$facts['defaults']??[];
        // Contractual annual holder renewals survive sale stops; their missing cost still needs repair.
        if($this->retainedRenewal($row,$action)){return 'sold';}
        if(!($intent['sell']??$defaults['defaultSell']??true)||in_array($action,$intent['stops']??[],true)
            ||$action==='register'&&$this->years>($intent['longest']??$defaults['defaultLongest']??1)
            ||$action==='transfer'&&!($intent['transfers']??$defaults['defaultTransfers']??false)){return 'off';}
        $reason=$facts['offers'][$action.':'.$this->years]->decision->reason??null;
        return in_array($reason,['staff_stopped','staff_term_stopped','renewal_stopped'],true)?'off':'sold';
    }

    private function retainedRenewal(OfferRow $row,string $action): bool
    {
        $retained=$this->savedRow($row)['prices']['renew']['price']??null;
        return $action==='renew'&&$this->years===1&&$retained!==null
            &&\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($retained)>0;
    }

    private static function cellOf(?string $text,?string $tone=null,?string $hint=null): array {return ['text'=>$text,'tone'=>$tone,'hint'=>$hint,'mark'=>false];}
    private static function missing(string $step): array {return ['text'=>'Missing','tone'=>'danger','hint'=>$step,'mark'=>true];}
    private static function loss(bool $below,bool $loses): ?string {return $below?'This price is below registrar cost.':($loses?'This price loses money after the card fee.':null);}
    public static function time(int $at): string {return gmdate('M j, Y H:i',$at).' UTC';}
    public static function key(OfferRow $row): string {return $row->server_id.':'.$row->tld;}
}
