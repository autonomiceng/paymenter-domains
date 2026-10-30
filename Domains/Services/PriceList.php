<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
/** Saved exact-term totals only. Never republishes, recomputes retail or contacts a registrar. */
final class PriceList
{
    public const FILTERS=['all'=>'All TLDs','below_cost'=>'Below cost','loses_money'=>'Loses money after card fee','default'=>'Default pricing','own'=>'Custom pricing'];
    public const ACTIONS=['register'=>'Register','renew'=>'Renew','transfer'=>'Transfer'];
    /** Sync never fills a price for sales that are off, so this is not Missing. */
    public const TRANSFERS_OFF='Transfers off';
    public static function authorize(): void {abort_unless(Staff::can(Staff::COSTS),403);}
    public function read(int $years=1,string $filter='all',?int $serverId=null): array
    {
        self::authorize();
        if($years<1||$years>10||!array_key_exists($filter,self::FILTERS)){throw new \InvalidArgumentException('Choose 1 to 10 years and a price filter.');}
        $query=TldFacts::bound(Tld::query())->with('server:id,name')->orderBy('tld')->orderBy('server_id');
        $all=$query->get();$accounts=$all->pluck('server.name','server_id')->all();
        $tlds=$serverId===null?$all:$all->where('server_id',$serverId);
        $saved=Price::whereIn('server_id',$tlds->pluck('server_id')->unique())->whereIn('tld',$tlds->pluck('tld')->unique())
            ->where('term',$years)->where('currency','USD')->get()->keyBy(fn(Price $p)=>$p->server_id.':'.$p->tld.':'.$p->action);
        $rules=new PricingRules;$fees=[];$defaults=[];$rows=[];$skipped=[];
        foreach($tlds as $tld){
            // Rule-source filters are known without reading settings; value filters cannot classify omissions.
            if(($filter==='default'&&!$tld->use_default_rule)||($filter==='own'&&$tld->use_default_rule)){continue;}
            $id=(int)$tld->server_id;
            $identity=['id'=>(int)$tld->id,'tld'=>'.'.$tld->tld,'server_id'=>$id,'account'=>(string)$tld->server->name,'years'=>$years,
                'pricing'=>$tld->use_default_rule?'Default':'Custom','currency'=>'USD'];
            if(!array_key_exists($id,$fees)){$fees[$id]=self::readSetting(fn()=>CardFee::read($id));}
            $fee=$fees[$id];
            if($fee===null){$skipped[]=[...$identity,'cause'=>'card_fee'];continue;}
            if($tld->use_default_rule){
                if(!array_key_exists($id,$defaults)){$defaults[$id]=self::readSetting(fn()=>$rules->defaultRule($id,false,$fee));}
                $rule=$defaults[$id];
            }else{$rule=self::readSetting(fn()=>$rules->rule($tld,false,$fee));}
            if($rule===null){$skipped[]=[...$identity,'cause'=>$tld->use_default_rule?'default_rule':'custom_rule'];continue;}
            $row=[...$identity,'prices'=>[],
                'formula'=>'Price = '.PricingRule::describe($rule).($rule['mode']==='fixed'?', times years.':', per year, times years.'),'fee'=>$fee,'fee_in_price'=>$rule['mode']==='markup_fee_percent'];
            $below=$loss=false;
            foreach(self::ACTIONS as $action=>$label){
                $p=$saved[$id.':'.$tld->tld.':'.$action]??null;
                if($p&&($p->account_fingerprint!==$tld->account_fingerprint||$p->registrar_key!==$tld->registrar_key)){$p=null;}
                // Annual holder billing retains positive saved retail when costs are quarantined, without a rule hash.
                // Acquisition still needs publication proof; legacy cost-only rows never become an offered price.
                $retainedRenewal=$p&&$action==='renew'&&$years===1&&$p->unit==='term_total'
                    &&$p->retail!==null&&PricingRule::minor(PriceCatalogue::retail($p->retail))>0;
                $published=$p&&($retainedRenewal||(isset($p->eligibility['rule_hash'])&&!($p->eligibility['price_unavailable']??false)));
                $price=$published&&$p->retail!==null?PriceCatalogue::retail($p->retail):null;
                $cost=$p?->wholesale===null||($p->eligibility['price_unavailable']??false)?null:PriceCatalogue::cost($p->wholesale);
                $profit=$price!==null&&$cost!==null?CardFee::profit($price,$cost,$fee):null;
                $under=$price!==null&&$cost!==null&&!PricingRule::covers($price,$cost);
                $loses=$profit!==null&&str_starts_with($profit,'-');$below=$below||$under;$loss=$loss||$loses;
                $row['prices'][$action]=['price'=>$price,'cost'=>$cost,'profit'=>$profit,'revision'=>$published?$p->revision:null,
                    'below_cost'=>$under,'loses_money'=>$loses,'status'=>$price===null?($action==='transfer'&&!$tld->transfer_enabled?self::TRANSFERS_OFF:'Missing'):($cost===null?'Cost missing':($under?'Below cost':($loses?'Loses money after card fee':'')))];
            }
            if(match($filter){'below_cost'=>!$below,'loses_money'=>!$loss,'default'=>!$tld->use_default_rule,'own'=>$tld->use_default_rule,default=>false}){continue;}
            $rows[]=$row;
        }
        $explanations=[];
        foreach($rows as $row){
            $key=$row['server_id'].':'.$row['pricing'].':'.$row['formula'];
            $explanations[$key]??=['account'=>$row['account'],'pricing'=>$row['pricing'],'formula'=>$row['formula'],'fee'=>$row['fee'],'fee_in_price'=>$row['fee_in_price'],'tlds'=>[]];
            $explanations[$key]['tlds'][]=$row['tld'];
        }
        $warnings=[];
        foreach($skipped as $row){
            $key=$row['server_id'].':'.$row['cause'].($row['cause']==='custom_rule'?':'.$row['id']:'');
            $warnings[$key]??=['server_id'=>$row['server_id'],'account'=>$row['account'],'cause'=>$row['cause'],'tlds'=>[]];
            $warnings[$key]['tlds'][]=$row['tld'];
        }
        return ['rows'=>$rows,'skipped'=>$skipped,'warnings'=>array_values($warnings),'accounts'=>$accounts,'years'=>$years,'explanations'=>array_values($explanations)];
    }
    /** Only saved-setting validation failures are recoverable here; database and programmer errors propagate. */
    private static function readSetting(\Closure $read): ?array
    {
        try{return $read();}catch(\DomainException|\InvalidArgumentException){return null;}
    }
    public function headings(): array
    {
        $out=['TLD','Registrar account','Years','Pricing','Currency'];
        foreach(self::ACTIONS as $label){foreach(['cost','price','est. profit','status'] as $name){$out[]=$label.' '.$name;}}
        return [...$out,'Current pricing formula','Card fee'];
    }
    /** Shared display strings keep downloads and the visible matrix equal, including missing values. */
    public function values(array $row): array
    {
        $out=[$row['tld'],$row['account'],(string)$row['years'],$row['pricing'],$row['currency']];
        if(isset($row['cause'])){
            $status=match($row['cause']){'card_fee'=>"Left out: card fee can't be read",'default_rule'=>"Left out: default pricing can't be read",'custom_rule'=>"Left out: custom pricing can't be read"};
            foreach(self::ACTIONS as $label){$out=[...$out,'Left out','Left out','Left out',$status];}
            return [...$out,'Left out','Left out'];
        }
        foreach(self::ACTIONS as $action=>$label){$p=$row['prices'][$action];$none=$p['status']===self::TRANSFERS_OFF?'Off':'Missing';
            $out=[...$out,$p['cost']===null?$none:self::money($p['cost']),$p['price']??$none,$p['profit']===null?$none:self::money($p['profit'],true),$p['status']==='Missing'?'':$p['status']];}
        return [...$out,$row['formula'],CardFee::describe($row['fee'])];
    }
    /** Display cents only; round estimated profit down, including a loss, without changing saved values. */
    private static function money(?string $value,bool $down=false): string
    {
        if($value===null){return 'Missing';}
        $negative=str_starts_with($value,'-');$absolute=ltrim($value,'-');
        [$whole,$fraction]=explode('.',$absolute);$fraction=str_pad($fraction,4,'0');
        $minor=(int)$whole*100+(int)substr($fraction,0,2);$remainder=(int)substr($fraction,2);
        if($down?($negative&&$remainder>0):$remainder>=50){$minor++;}
        return PricingRule::decimal($negative?-$minor:$minor);
    }
    public function csv(int $years=1,string $filter='all',?int $serverId=null): string
    {
        $matrix=$this->read($years,$filter,$serverId);
        $stream=fopen('php://memory','w+');
        $write=static function(array $values)use($stream):void {
            // A staff-editable account name must not become a spreadsheet formula.
            $values=array_map(static fn(string $value):string=>preg_match('/^[\s]*[=+@\-]/u',$value)&&!preg_match('/^-\d+\.\d+$/D',$value)?"'".$value:$value,$values);
            fputcsv($stream,$values,',','"','');
        };
        $rows=[...$matrix['rows'],...$matrix['skipped']];
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['tld'],$b['tld'])?:$a['server_id']<=>$b['server_id']);
        $write($this->headings());foreach($rows as $row){$write($this->values($row));}
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
}
