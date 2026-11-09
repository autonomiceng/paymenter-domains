<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Services\AccountSpendingHolds;
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
    /** The notice line for the existing management spending hold. */
    public static function holdSummary(Operation $op, ?StaffGrants $grants = null): ?string
    {
        $grants ??= Staff::requireView();
        if (!AccountSpendingHolds::matches($op)) { return null; }
        return 'Paid domain changes on this '.self::registrar($op).' account wait until the result is known'.self::age($op).'. Registrations and renewals still check the balance before sending.';
    }
    private static function age(Operation $op): string
    {
        $at=$op->started_at??$op->created_at;
        $hours=$at ? max(0,(int)floor((time()-$at->getTimestamp())/3600)) : null;
        return match($hours){null=>'',0=>' (under 1 hour so far)',1=>' (1 hour so far)',default=>' ('.$hours.' hours so far)'};
    }
    /** The account-wide hold, said where staff look first: the Domains list and the registrar account page. Only a hold staff must clear shows:
     * a request still with the registrar clears by itself. One line per account: the job first, as on a held domain's head, then what it holds.
     * The count is the account's Under review requests, the rows its link opens. Null with none.
     * A fixed number of reads, however many accounts are held: the held accounts, their Under review counts, then their names. */
    public static function accounts(?int $server=null): ?\Illuminate\Support\HtmlString
    {
        Staff::requireView();
        $off=OperationQueries::ordersOffAccounts();
        $held=OperationQueries::status(AccountSpendingHolds::query(Operation::query()),['under_review'],$off)->when($server!==null,fn($q)=>$q->where('server_id',$server))
            ->selectRaw('server_id, min(id) as first')->groupBy('server_id')->orderBy('server_id')->limit(100)->pluck('first','server_id');
        if ($held->isEmpty()) { return null; }
        $ids=$held->keys()->map(fn($id)=>(int)$id)->all();
        $reviews=OperationQueries::status(OperationResource::getEloquentQuery()->whereIn('domains_operations.server_id',$ids),['under_review'],$off)->toBase()
            ->selectRaw('domains_operations.server_id as server_id, count(*) as total, min(domains_operations.id) as first')->groupBy('domains_operations.server_id')->get()->keyBy('server_id');
        $names=\App\Models\Server::whereIn('id',$ids)->pluck('name','id');
        $lines=[];
        foreach ($held as $id=>$oldest) {
            $review=$reviews->get($id);
            // A request the list can't show (an older registration of the domain) is still named, by itself.
            $count=max(1,(int)($review->total??0)); $first=$count===1?((int)($review->first??0) ?: (int)$oldest):0;
            $name=e($names->get($id) ?: 'this registrar account');
            [$label,$url,$until]=$count===1
                ? ['Check request #'.$first,DomainFacts::requestUrl(['request'=>$first]),'it is checked']
                : ['Check '.$count.' requests',RegistrarFacts::requestsUrl((int)$id,['under_review']),'they are checked'];
            $lines[]=($url?DomainFacts::requestLink($url,$label):e($label)).' Paid changes on '.$name.' are on hold until '.$until.'.';
        }
        return $lines?new \Illuminate\Support\HtmlString(implode('<br>',$lines)):null;
    }
    public static function title(Operation $op): string
    { return self::registrar($op).' has not confirmed this '.(['register'=>'registration','transfer'=>'transfer','renew'=>'renewal'][$op->action]??'change').'.'; }
    public static function registrar(Operation $op): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??'Registrar'; }
    /** Requests holding paid management changes on this domain's registrar account. */
    public static function for(Domain $d): array
    {
        $grants=Staff::requireView();
        return self::rows(AccountSpendingHolds::query(Operation::where('account_fingerprint',$d->account_fingerprint)),fn($op)=>self::holdSummary($op,$grants)?'Request #'.$op->id.' unconfirmed':null);
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
