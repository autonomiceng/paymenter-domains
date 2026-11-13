<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Services\AccountSpendingHolds;
use Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility;
/** Read-only projection, never a settlement or spending-release authority. */
final class PaidUncertainty
{
    /** Paid and the registrar result is not known yet: the request notice title. */
    public static function summary(Operation $op, ?StaffGrants $grants = null): ?string
    {
        $grants ??= Staff::requireView();
        if ($op->funding_basis!=='paid_invoice' || (!in_array($op->state,['running','pending','uncertain'],true)
            && !in_array($op->safe_code,['transfer_provider_rejected','transfer_needs_action','management_provider_refused'],true))) { return null; }
        return self::title($op);
    }
    /** The notice line for the account-wide spending hold. The hold stops only paid management orders (WHOIS privacy), never
     * registrations, renewals or transfers, so the line says that once. Its time is the account hold's: the oldest request holding it.
     * While WHOIS privacy is not sold the hold stops nothing a customer can order, so there is no line. */
    public static function holdSummary(Operation $op, ?StaffGrants $grants = null): ?string
    {
        $grants ??= Staff::requireView();
        if (!PrivacyVisibility::SHOWN || !AccountSpendingHolds::matches($op)) { return null; }
        return 'Until it is settled, only paid WHOIS privacy orders on this '.self::registrar($op).' account wait'.self::since($op).'.';
    }
    /** When the account hold began: the oldest request on this account that holds it. Every request on the account shows this one time. */
    private static function since(Operation $op): string
    {
        $at=AccountSpendingHolds::query(Operation::where('account_fingerprint',$op->account_fingerprint))->selectRaw('min(coalesce(started_at,created_at)) as first')->value('first')
            ?? ($op->started_at??$op->created_at);
        if ($at===null) { return ''; }
        $time=$at instanceof \DateTimeInterface?$at->getTimestamp():\Illuminate\Support\Carbon::parse((string)$at)->getTimestamp();
        return ' (on hold since '.\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at($time).')';
    }
    /** The account-wide hold, said where staff look first: the Domains list and the registrar account page. Only a hold staff must clear shows:
     * a request still with the registrar clears by itself. One line per account: the job first, as on a held domain's head, then what it holds.
     * The count is the account's Under review requests, the rows its link opens. Null with none.
     * A fixed number of reads, however many accounts are held: the held accounts, their Under review counts, then their names. */
    public static function accounts(?int $server=null): ?\Illuminate\Support\HtmlString
    {
        $lines=[];
        foreach (self::holds($server) as $h) { $lines[]=($h['url']?DomainFacts::requestLink($h['url'],$h['label']):e($h['label'])).' '.e(self::line($h)); }
        return $lines?new \Illuminate\Support\HtmlString(implode('<br>',$lines)):null;
    }
    /** The Registrars list Note for a held account: the same step as its banner, so the list never shows the account as clear. */
    public static function accountStep(int $server): ?string
    {
        Staff::requireView();
        $attributes=request()->attributes;$key='domains.paid_uncertainty.holds';
        if(!$attributes->has($key)){$attributes->set($key,self::holds(null));}
        $holds=$attributes->get($key);
        $h=collect($holds)->firstWhere('server_id',$server);
        // A server beyond the bounded snapshot still gets its own checked result.
        if($h===null&&count($holds)===100){$h=self::holds($server)[0]??null;}
        return $h?$h['label'].(PrivacyVisibility::SHOWN?'. Paid WHOIS privacy orders wait.':'. No registrar result yet.'):null;
    }
    /** What a held account's paid orders wait for, as one sentence. @param array{name:string,until:string,count:int} $h */
    public static function line(array $h): string
    {
        return PrivacyVisibility::SHOWN ? 'Paid WHOIS privacy orders on '.$h['name'].' wait until '.$h['until'].'.'
            : ($h['count']===1?'A paid order on '.$h['name'].' has':'Paid orders on '.$h['name'].' have').' no registrar result yet.';
    }
    /** @return list<array{server_id:int,label:string,url:?string,until:string,name:string,count:int}> */
    public static function holds(?int $server=null): array
    {
        Staff::requireView();
        $off=OperationQueries::ordersOffAccounts();
        $held=OperationQueries::status(AccountSpendingHolds::query(Operation::query()),['under_review'],$off)->when($server!==null,fn($q)=>$q->where('server_id',$server))
            ->selectRaw('server_id, min(id) as first')->groupBy('server_id')->orderBy('server_id')->limit(100)->pluck('first','server_id');
        if ($held->isEmpty()) { return []; }
        $ids=$held->keys()->map(fn($id)=>(int)$id)->all();
        $reviews=OperationQueries::status(OperationResource::getEloquentQuery()->whereIn('domains_operations.server_id',$ids),['under_review'],$off)->toBase()
            ->selectRaw('domains_operations.server_id as server_id, count(*) as total, min(domains_operations.id) as first')->groupBy('domains_operations.server_id')->get()->keyBy('server_id');
        $names=\App\Models\Server::whereIn('id',$ids)->pluck('name','id');
        $out=[];
        foreach ($held as $id=>$oldest) {
            $review=$reviews->get($id);
            // A request the list can't show (an older registration of the domain) is still named, by itself.
            $count=max(1,(int)($review->total??0)); $first=$count===1?((int)($review->first??0) ?: (int)$oldest):0;
            [$label,$url,$until]=$count===1
                ? ['Check request #'.$first,DomainFacts::requestUrl(['request'=>$first]),'it is checked']
                : ['Check '.$count.' requests',RegistrarFacts::requestsUrl((int)$id,['under_review']),'they are checked'];
            $out[]=['server_id'=>(int)$id,'label'=>$label,'url'=>$url,'until'=>$until,'name'=>(string)($names->get($id) ?: 'this registrar account'),'count'=>$count];
        }
        return $out;
    }
    public static function title(Operation $op): string
    { return self::registrar($op).' has not confirmed this '.(['register'=>'registration','transfer'=>'transfer','renew'=>'renewal'][$op->action]??'change').'.'; }
    public static function registrar(Operation $op): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??'Registrar'; }
    /** Requests holding paid management changes on this domain's registrar account. */
    public static function for(Domain $d): array
    {
        Staff::requireView();
        return self::rows(AccountSpendingHolds::query(Operation::where('account_fingerprint',$d->account_fingerprint)),fn($op)=>AccountSpendingHolds::matches($op)?'Request #'.$op->id.' unconfirmed':null);
    }
    /** Paid requests on this domain's registrar account whose result is not known. */
    public static function reviews(Domain $d): array
    {
        $grants=Staff::requireView();
        return self::rows(OperationQueries::paidUncertainty(Operation::where('account_fingerprint',$d->account_fingerprint))->whereNotIn('state',['running','pending','uncertain']),fn($op)=>self::summary($op,$grants)?'Request #'.$op->id.' unconfirmed':null);
    }
    private static function rows(\Illuminate\Database\Eloquent\Builder $query,\Closure $summary): array
    {
        $rows=$query->orderBy('id')->limit(26)->get();$out=$rows->take(25)->map($summary)->filter()->values()->all();
        if($rows->count()>25){$out[]='More requests are under review.';}return $out;
    }
}
