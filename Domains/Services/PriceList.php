<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
/** Saved exact-term totals only. Never republishes, recomputes retail or contacts a registrar. */
class PriceList
{
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue
    {return \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();}
    public const FILTERS=['all'=>'All TLDs','below_cost'=>'Below cost','loses_money'=>'Loses money after card fee','default'=>'Default pricing','own'=>'Custom pricing'];
    public const ACTIONS=['register'=>'Register','renew'=>'Renew','transfer'=>'Transfer'];
    /** Sync never fills a price for sales that are off, so this is not Missing. */
    public const TRANSFERS_OFF='Transfers off';
    public static function authorize(): void {abort_unless(Staff::can(Staff::COSTS),403);}
    public function read(int $years=1,string $filter='all',?int $serverId=null): array
    {
        self::authorize();
        if($years<1||$years>10||!array_key_exists($filter,self::FILTERS)){throw new \InvalidArgumentException('Choose 1 to 10 years and a price filter.');}
        if(($catalogue=$this->catalogue())!==null){
            $tlds=TldFacts::bound(Tld::query())->where('catalogue_converted',true)->when($serverId!==null,fn($q)=>$q->where('server_id',$serverId))->orderBy('tld')->orderBy('server_id')->get();
            return $catalogue->matrix($tlds,$years,$filter);
        }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }

    /** One formula per account and pricing type, with the TLDs it prices. Grouped by account id, since two accounts can share a name. */
    public static function explain(array $rows): array
    {
        $explanations=[];
        foreach($rows as $row){
            $key=$row['server_id'].':'.$row['pricing'].':'.$row['formula'];
            $explanations[$key]??=['account'=>$row['account'],'pricing'=>$row['pricing'],'formula'=>$row['formula'],'fee'=>$row['fee'],'fee_in_price'=>$row['fee_in_price'],'tlds'=>[]];
            $explanations[$key]['tlds'][]=$row['tld'];
        }
        return array_values($explanations);

    }
    /** Only saved-setting validation failures are recoverable here; database and programmer errors propagate. */

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
            $out=[...$out,$p['cost']===null?$none:self::money($p['cost']),$p['price']??$none,$p['profit']===null?$none:self::money($p['profit'],true),in_array($p['status'],['Missing',self::TRANSFERS_OFF],true)?'':$p['status']];}
        return [...$out,$row['formula'],CardFee::describe($row['fee'])];
    }
    /** Estimated profit as it shows on What you sell and in the CSV. */
    public static function profit(string $value): string {return self::money($value,true);}
    /** Saved cost as it shows on What you sell and in the CSV. */
    public static function cost(string $value): string {return self::money($value);}
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
    /** @param ?list<string> $only server:tld keys to keep: the rows a table holds. */
    public function csv(int $years=1,string $filter='all',?int $serverId=null,?array $only=null): string
    {
        $matrix=$this->read($years,$filter,$serverId);
        $stream=fopen('php://memory','w+');
        $write=static function(array $values)use($stream):void {
            // A staff-editable account name must not become a spreadsheet formula.
            $values=array_map(static fn(string $value):string=>preg_match('/^[\s]*[=+@\-]/u',$value)&&!preg_match('/^-\d+\.\d+$/D',$value)?"'".$value:$value,$values);
            fputcsv($stream,$values,',','"','');
        };
        $rows=[...$matrix['rows'],...$matrix['skipped']];
        if($only!==null){$keep=array_flip($only);$rows=array_filter($rows,static fn(array $row):bool=>isset($keep[$row['server_id'].':'.ltrim($row['tld'],'.')]));}
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['tld'],$b['tld'])?:$a['server_id']<=>$b['server_id']);
        $write($this->headings());foreach($rows as $row){$write($this->values($row));}
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
}
