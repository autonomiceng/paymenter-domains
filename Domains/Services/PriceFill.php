<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffAlert};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Catalogue,Driver as EnomDriver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Driver as NameSiloDriver;
/** The only code that reads prices for What you sell: once a day, or from Sync now. A failed read writes nothing,
 * so the last good rows stay. These are reseller costs for the page, not spend evidence: PriceSync and the quote
 * at preflight still prove every sale. */
final class PriceFill
{
    public const EVERY_SECONDS=86400;
    /** Prices rarely change, so a missed day does no harm. Staff hear about it after this long. */
    public const STALE_SECONDS=259200;
    /** One read per price bucket. A term uses the bucket at or under it. */
    public const YEARS=[1,2,5,10];
    public function __construct(private readonly AccountBindings $bindings,private readonly Clock $clock) {}
    public static function bucket(int $term): int
    { return max(array_filter(self::YEARS,static fn(int $years)=>$years<=$term)); }
    private static function state(AccountBinding $binding): \Illuminate\Database\Query\Builder
    { return DB::table(SellTableSchema::FILLS)->where(['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint]); }
    public static function last(AccountBinding $binding): ?object { return self::state($binding)->first(); }
    /** No good fill for STALE_SECONDS. An account that never had one counts from its first failure. */
    public static function stale(?object $state,int $now): bool
    { $since=$state?->filled_at??$state?->failed_at;return $since!==null&&$now-(int)$since>=self::STALE_SECONDS; }
    /** Enom follows the catalogue switch. NameSilo has a reader only once its account can give one. */
    public function reader(AccountBinding $binding): EnomDriver|NameSiloDriver|null
    {
        if($binding->registrarKey==='enom'&&!TestCatalogue::enabled($binding)){return null;}
        try{$reader=$this->bindings->reader($binding->serverId,true);}
        catch(\DomainException $error){if($binding->registrarKey==='enom'){throw $error;}return null;}
        return $reader instanceof EnomDriver||$reader instanceof NameSiloDriver?$reader:null;
    }
    /** A deferred or failed run waits for the next day too: retrying each tick could spend our send limit. */
    public function daily(Account $account): void
    {
        OperationClaims::outside();$binding=CatalogueAcceptances::binding($account);
        $now=$this->clock->now()->getTimestamp();$due=$now-self::EVERY_SECONDS;
        $key=['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint];
        DB::table(SellTableSchema::FILLS)->insertOrIgnore($key);
        $tried=self::last($binding)->tried_at;if($tried!==null&&(int)$tried>$due){return;}
        try{
            if(self::state($binding)->where(fn($q)=>$q->whereNull('tried_at')->orWhere('tried_at','<=',$due))->update(['tried_at'=>$now])!==1){return;}
            $reader=$this->reader($binding);if($reader===null){return;}
            $this->fill($binding,$reader,RequestOrigin::Background);
        }catch(\Throwable $error){$this->failed($account,$binding,$error,$now);}
    }
    /** Sync now. The caller shows what stopped it. @return int rows written, 0 when a newer read took over */
    public function run(Account $account): int
    {
        OperationClaims::outside();$binding=CatalogueAcceptances::binding($account);
        $reader=$this->reader($binding)??throw new \DomainException('TEST catalogue reads are unavailable.');
        return $this->fill($binding,$reader,RequestOrigin::Interactive);
    }
    private function failed(Account $account,AccountBinding $binding,\Throwable $error,int $now): void
    {
        $state=self::last($binding);$code=ReadFailure::code($error);$since=(int)($state->filled_at??$state->failed_at??$now);
        self::state($binding)->update(['failed_at'=>$state->failed_at??$now,'failure'=>$code]);
        Log::warning('Domains price fill failed.',['server_id'=>$binding->serverId,'code'=>$code]);
        if(!self::stale(self::last($binding),$now)){return;}
        $name=(string)(\App\Models\Server::whereKey($binding->serverId)->value('name')??'A registrar account');
        StaffAlert::send('Prices are out of date',$name.' has not given us new prices for 3 days. What you sell still shows the last good prices. Open it and use Sync now to see what stopped the read.',
            'filament.admin.pages.what-you-sell',Staff::POLICY,'sell_fill:'.$binding->serverId.':'.$binding->fingerprint.':'.$since);
    }
    private function fill(AccountBinding $binding,EnomDriver|NameSiloDriver $reader,RequestOrigin $origin): int
    {
        $run=self::start($binding);$first=self::last($binding)->filled_at===null;
        // Our reads for this run: every bucket, plus the list once so TLDs without a price row still show.
        $context=new RequestContext($binding,$origin,$this->clock->now()->modify('+60 seconds'),new RequestBudget(count(self::YEARS)+1),$this->clock,Runtime::limiter(),stopOnReadFailure:true);
        $rows=$reader instanceof NameSiloDriver?self::nameSilo($reader,$context):self::enom($reader,$context,$first);
        if($rows===[]){throw new \DomainException('Price read failed.');}
        $now=$this->clock->now()->getTimestamp();$records=[];
        foreach($rows as $tld=>$buckets){
            $one=$buckets[1]??[];$register=$one['register']??null;$renew=$one['renew']??null;
            $high=$register===null||$renew===null?null:(PricingRule::units($register)>=PricingRule::units($renew)?$register:$renew);
            $records[]=['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint,'tld'=>(string)$tld,'register_cost'=>$register,'renew_cost'=>$renew,
                'transfer_cost'=>$one['transfer']??null,'high_cost'=>$high,'prices'=>$buckets===[]?null:json_encode($buckets,JSON_THROW_ON_ERROR),'read_at'=>$now];
        }
        return DB::transaction(static function()use($binding,$records,$now,$run):int {
            // Runs can overlap across their reads. The last one started wins, so older prices never land on newer ones.
            if((int)self::state($binding)->lockForUpdate()->value('run')!==$run){return 0;}
            // Staff choices stay. A TLD missing from this read keeps its last prices and their read time.
            DB::table(SellTableSchema::ROWS)->upsert($records,['server_id','account_fingerprint','tld'],['register_cost','renew_cost','transfer_cost','high_cost','prices','read_at']);
            self::state($binding)->update(['filled_at'=>$now,'tried_at'=>$now,'failed_at'=>null,'failure'=>null]);
            return count($records);
        });
    }
    private static function start(AccountBinding $binding): int
    {
        DB::table(SellTableSchema::FILLS)->insertOrIgnore(['server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint]);
        return DB::transaction(static function()use($binding):int {self::state($binding)->increment('run');return (int)self::last($binding)->run;});
    }
    /** @return array<string,array<int,array>> */
    private static function enom(EnomDriver $reader,RequestContext $context,bool $first): array
    {
        $rows=[];
        foreach(self::YEARS as $years){
            $read=$reader->bulkPricing(false,$years,$context);
            if($read->status!=='success'){throw new \DomainException('Price read failed.');}
            foreach($read->facts()['rows'] as $tld=>$row){
                if(!self::named((string)$tld)){continue;}
                $rows[$tld][$years]=['register'=>self::cost($row['resellerpricereg'],$row['registerenabled']),'renew'=>self::cost($row['resellerpricerenew'],$row['renewenabled']),'transfer'=>self::cost($row['resellerpricetran'],$row['transferenabled'])];
            }
        }
        foreach($rows as $buckets){if(count($buckets)!==count(self::YEARS)){throw new \DomainException('Price read failed.');}}
        if(!$first){return $rows;}
        $list=$reader->catalogue($context);
        if($list->status!=='success'){throw new \DomainException('Price read failed.');}
        foreach($list->facts()['tlds'] as $tld){if(self::named((string)$tld)){$rows[$tld]??=[];}}
        return $rows;
    }
    /** @return array<string,array<int,array>> yearly prices only: its annual terms are all it can prove */
    private static function nameSilo(NameSiloDriver $reader,RequestContext $context): array
    {
        $rows=[];
        foreach($reader->prices($context) as $tld=>$row){
            if(!self::named((string)$tld)){continue;}
            // One broken row must not stop the rest: it stays out of this read and keeps its last good prices.
            try{$rows[$tld]=[1=>['register'=>self::cost($row['registration']??null),'renew'=>self::cost($row['renew']??null),'transfer'=>self::cost($row['transfer']??null)]];}
            catch(\DomainException){continue;}
        }
        return $rows;
    }
    /** Enom also lists names no order could use: IDN forms and TEST-only entries. */
    private static function named(string $tld): bool
    { try{Catalogue::tld($tld);return true;}catch(\InvalidArgumentException){return false;} }
    /** A disabled price comes back as 0.00. That is no price, not a free product. Anything else without a real price
     * is a broken reply: it throws, so it can never replace a good price. */
    private static function cost(?string $value,bool|null $enabled=true): ?string
    {
        if($enabled===false){return null;}
        $cost=$enabled===true?PricingRule::wholesale($value):null;
        if($cost===null||PricingRule::units($cost)<=0){throw new \DomainException('Price read failed.');}
        return $cost;
    }
}
