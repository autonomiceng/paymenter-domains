<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName,CatalogueAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\{Account,SellRow};
/** A default for TLDs nobody set (except: sell them, only: don't), each row's own choice, and one cost limit. Turns the
 * rule and the rows into acceptance changes for the existing guarded write. Saved 'all' rules still read; the next save converts them. It reads only our table and never contacts a registrar. */
final class SellRule
{
    public const KEY='domains_sell_rule';
    public const MODES=['all','except','only'];
    private const CHANGES_IN_AUDIT=50;
    public static function rows(AccountBinding $binding): \Illuminate\Database\Eloquent\Builder
    { return SellRow::where(['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint]); }
    /** A saved list from before this rule stays exactly as it is. A new account starts by selling every TLD nobody set. */
    public static function read(AccountBinding $binding,?CatalogueAcceptance $accepted): array
    {
        $saved=self::saved($binding);
        return $saved??['mode'=>$accepted!==null&&$accepted->endings!==[]?'only':'except','max_cost'=>null,'years'=>1,'transfers'=>false];
    }
    private static function saved(AccountBinding $binding): ?array
    { $value=Server::findOrFail($binding->serverId)->settings()->where('key',self::KEY)->first()?->value;return is_array($value)?self::validate($value):null; }
    public static function validate(array $rule): array
    {
        $years=$rule['years']??null;$cost=$rule['max_cost']??null;$cost=$cost===null||$cost===''?null:PricingRule::wholesale((string)$cost);
        if(!in_array($rule['mode']??null,self::MODES,true)||!is_int($years)||$years<1||$years>10||!is_bool($rule['transfers']??null)
            ||(($rule['max_cost']??'')!==''&&$cost===null)){throw new \InvalidArgumentException('Invalid sell rule.');}
        return ['mode'=>$rule['mode'],'max_cost'=>$cost,'years'=>$years,'transfers'=>$rule['transfers']];
    }
    /** Every accepted TLD has a row. Until a rule is saved, rows copy what the account already sells. */
    public static function ensure(AccountBinding $binding,?CatalogueAcceptance $accepted): void
    {
        if($accepted===null||$accepted->endings===[]){return;}
        $key=['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint];
        DB::table(SellTableSchema::ROWS)->insertOrIgnore(array_map(static fn(string $tld)=>[...$key,'tld'=>$tld],array_keys($accepted->endings)));
        if(self::saved($binding)!==null){return;}
        $rows=self::rows($binding)->whereNull('choice')->whereIn('tld',array_keys(array_filter($accepted->endings,static fn(array $row)=>$row['register']!==[])))->get();
        foreach($rows as $row){
            $sold=$accepted->endings[$row->tld];
            $row->fill(['choice'=>'sell','longest'=>max($sold['register']),'transfer'=>$sold['transfer']!==[]])->save();
        }
    }
    /** What the rule says for one row: sell, skipped, limit, or waiting (no cost read yet). */
    public static function decide(SellRow $row,array $rule): string
    {
        $listed=match($rule['mode']){'all'=>true,'except'=>$row->choice!=='skip',default=>$row->choice==='sell'};
        if(!$listed||EndingChoices::hidden($row->tld)){return 'skipped';}
        if($row->high_cost===null){return $row->choice==='sell'?'sell':'waiting';}
        return $rule['max_cost']!==null&&PricingRule::units((string)$row->high_cost)>PricingRule::units($rule['max_cost'])?'limit':'sell';
    }
    /** @return list<int> years to sell, leaving out any the registrar has switched off */
    private static function terms(SellRow $row,array $rule,bool $annual): array
    {
        if($annual){return [1];}
        $terms=range(1,$row->longest??$rule['years']);
        return $row->prices===null?$terms:array_values(array_filter($terms,static fn(int $term)=>($row->prices[PriceFill::bucket($term)]['register']??null)!==null));
    }
    /** @return array<string,?array> acceptance changes. A row that already fits is left alone, so nothing widens by itself. */
    public static function changes(iterable $rows,array $rule,array $accepted,bool $annual=false): array
    {
        $changes=[];
        foreach($rows as $row){
            $current=$accepted[$row->tld]['register']??[];
            $terms=self::decide($row,$rule)==='sell'?self::terms($row,$rule,$annual):[];
            if($terms===[]){if($current!==[]){$changes[$row->tld]=null;}continue;}
            $transfer=($row->transfer??$rule['transfers'])&&($row->prices===null||$row->transfer_cost!==null);
            if($current!==[]&&max($current)===max($terms)&&($accepted[$row->tld]['transfer']!==[])===$transfer){continue;}
            $changes[$row->tld]=['terms'=>$terms,'transfer'=>$transfer];
        }
        ksort($changes);return $changes;
    }
    /** Saves the rule and rows, inside the review's transaction. @return array{0:array<string,?array>,1:array} changes and audit input */
    public static function applyLocked(Account $account,AccountBinding $binding,?CatalogueAcceptance $old,array $sell): array
    {
        self::ensure($binding,$old);
        $saved=self::read($binding,$old);$rule=isset($sell['rule'])?self::validate($sell['rule']):$saved;
        // 'all' ignored Don't sell choices, so clearing them keeps today's sales the same under 'except'.
        $cleared=$saved['mode']==='all'?self::rows($binding)->where('choice','skip')->update(['choice'=>null]):0;
        if($rule['mode']==='all'){$rule['mode']='except';}
        $choices=$sell['choices']??[];$terms=$sell['terms']??[];
        $key=['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint];
        foreach(array_keys($choices+$terms) as $tld){CanonicalName::parse('example.'.$tld,[(string)$tld]);}
        if($choices+$terms!==[]){DB::table(SellTableSchema::ROWS)->insertOrIgnore(array_map(static fn($tld)=>[...$key,'tld'=>(string)$tld],array_keys($choices+$terms)));}
        $touched=array_map('strval',array_keys($choices+$terms));$before=self::staffState($binding,$touched);
        foreach(['sell','skip',null] as $choice){
            $picked=array_keys(array_filter($choices,static fn($value)=>$value===$choice));
            if($picked!==[]){self::rows($binding)->whereIn('tld',array_map('strval',$picked))->update(['choice'=>$choice]);}
        }
        foreach($terms as $tld=>$row){
            $longest=$row['longest']??null;$transfer=$row['transfer']??null;
            if($longest!==null&&(!is_int($longest)||$longest<1||$longest>10)||$transfer!==null&&!is_bool($transfer)){throw new \InvalidArgumentException('Invalid sell terms.');}
            // A term left out stays as it is. A term equal to the default follows the default.
            $set=array_intersect_key(['longest'=>$longest===$rule['years']?null:$longest,'transfer'=>$transfer===$rule['transfers']?null:$transfer],$row);
            if($set!==[]){self::rows($binding)->where('tld',(string)$tld)->update($set);}
        }
        $edited=[];
        foreach(self::staffState($binding,$touched) as $tld=>$new){if($new!==$before[$tld]){$edited[$tld]=['old'=>$before[$tld],'new'=>$new];}}
        Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>self::KEY],['type'=>'array','encrypted'=>false,'value'=>$rule]);
        $changes=self::changes(self::rows($binding)->lazyById(500),$rule,$old?->endings??[],$binding->registrarKey==='namesilo');
        // A save from before this screen left out how orders are sent. One save catches up.
        if($changes===[]&&$old!==null&&TldFacts::ordersNeedSave($account,$old)){
            foreach(array_filter($old->endings,static fn(array $row)=>$row['register']!==[]) as $tld=>$row){$changes[$tld]=['terms'=>$row['register'],'transfer'=>$row['transfer']!==[]];}
        }
        // Audit entries have a size limit, so a whole-list change records its counts. The list itself is the rows.
        $audit=['rule'=>$rule,'counts'=>['added'=>count(array_filter($changes)),'removed'=>count($changes)-count(array_filter($changes)),'rows'=>count($edited),'cleared'=>$cleared]];
        return [$changes,$audit+(count($changes)>self::CHANGES_IN_AUDIT?[]:['changes'=>$changes])+(count($edited)>self::CHANGES_IN_AUDIT?[]:['rows'=>$edited])];
    }
    /** @return array<string,array> what staff set on these rows */
    private static function staffState(AccountBinding $binding,array $tlds): array
    { return $tlds===[]?[]:self::rows($binding)->whereIn('tld',$tlds)->get()->mapWithKeys(static fn(SellRow $row)=>[$row->tld=>$row->only(['choice','longest','transfer'])])->all(); }
}
