<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus as PaymentStatus;
use App\Livewire\Invoices\Show;
use App\Models\{Invoice,InvoiceTransaction};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Auth,Cache,Log};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Disposition,DriverResult,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Live name check when a customer starts to pay, before any money moves. Scoped to the native invoice pay call.
 * Local Live acceptance always precedes reads. A read that fails lets payment go: the after-payment preflight still guards the send. */
class PayCheck
{
    /** Fixed legacy route. No production composition selects the dormant adapter. */
    protected function catalogue(): ?AcquisitionOffers
    { return \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(AcquisitionOffers::class); }

    /** States of an order nobody has sent or set aside. */
    public const OPEN=['awaiting_payment','awaiting_code','queued'];
    private const CACHE_SECONDS=60;
    private const READ_SECONDS=5;
    private const CLEAR='clear';
    private const UNKNOWN='unknown';
    private const TAKEN='taken';
    private const NOT_TRANSFERABLE='not_transferable';
    private const CHANGED='changed';
    public function __construct(private readonly AccountBindings $bindings,private readonly Clock $clock){}
    public static function boot(): void
    {
        if(!HookRegistration::claim(app('livewire'),self::class)){return;}
        Livewire::listen('call',static function($component,$method,$params,$context,$returnEarly): void {
            if($component::class!==Show::class||$method!=='processPayment'||$component->selectedMethod===null){return;}
            $closed=app(self::class)->stop($component->invoice);if(!$closed){return;}
            $returnEarly();self::send($component,$closed);
        });
        // The hook runs before the native mount, so it must check the owner itself.
        Livewire::listen('mount',static function($component,$params): void {
            if($component::class!==Show::class||!($params['invoice']??null) instanceof Invoice){return;}
            $invoice=$params['invoice'];if($invoice->status!=='cancelled'||(int)$invoice->user_id!==(int)Auth::id()){return;}
            $closed=self::returning($invoice);if($closed){self::send($component,$closed);}
        });
    }
    /** The same words for customers and staff. 'expired' names no domain because one invoice can hold several. */
    public static function notice(string $why,string $name=''): array
    {
        return ['why'=>$why,...match($why){
            self::TAKEN=>['text'=>"Someone else registered {$name}. You have not been charged.",'link'=>'Search again'],
            self::NOT_TRANSFERABLE=>['text'=>"{$name} cannot be transferred now. You have not been charged.",'link'=>'Search again'],
            self::CHANGED=>['text'=>"The price of {$name} changed.",'link'=>'Search again to see the new price.'],
            'expired'=>['text'=>'This order expired.','link'=>'Search again'],
            'paid_late'=>['text'=>'This order had closed before your payment arrived. What you paid goes to your account credit.','link'=>'Search again'],
        }];
    }
    /** Stops payment for a temporary Live opt-out; closes an unavailable name. Null lets payment go. */
    public function stop(Invoice $invoice): ?array
    {
        if ($offers = $this->catalogue()) { return $this->catalogueStop($invoice, $offers); }
        try{
            $moneyMoving=self::moneyMoving((int)$invoice->id);
            $deadline=$this->clock->now()->modify('+'.self::READ_SECONDS.' seconds');
            foreach($this->orders($invoice) as $op){
                if(self::sellingStopped($op)){return ['why'=>'unavailable','text'=>'Domains can’t be ordered right now. Please try again later.','name'=>$op->domain->domain];}
                if($moneyMoving||!in_array($op->state,self::OPEN,true)){continue;}
                $answer=$this->answer($op,$deadline);
                if(!in_array($answer,[self::CLEAR,self::UNKNOWN],true)){return $this->close($invoice,$op,$answer);}
            }
        }catch(\Throwable $e){Log::warning('Domains pay check skipped.',['invoice_id'=>$invoice->id,'exception_class'=>$e::class]);}
        return null;
    }
    /** Local evidence fails closed; provider unknown keeps the retained paid-preflight behavior. */
    private function catalogueStop(Invoice $invoice, AcquisitionOffers $offers): ?array
    {
        if ($invoice->fresh()?->status !== 'pending' || self::moneyMoving((int)$invoice->id)) { return null; }
        $ops = $this->orders($invoice)->filter(fn ($op) => in_array($op->state, self::OPEN, true));
        if ($ops->isEmpty()) { return null; }
        $unavailable = ['why' => 'unavailable', 'text' => 'Domains can’t be ordered right now. Please try again later.'];
        try {
            $selections = $ops->map(static function ($op) {
                $receipt = $op->policy['checkout']['catalogue'] ?? throw new \DomainException('No catalogue receipt.');
                $s = new \Paymenter\Extensions\Servers\Domains\Data\SaleSelection(...$receipt);
                if ($s->serverId !== (int)$op->server_id || $s->fingerprint !== $op->account_fingerprint
                    || $s->action !== $op->action || $s->term !== (int)$op->term || $s->tld !== $op->domain->tld) {
                    throw new \DomainException('Frozen catalogue binding changed.');
                }
                return $s;
            })->values()->all();
            foreach ($selections as $s) { $offers->validate($s); }
            $deadline = $this->clock->now()->modify('+'.self::READ_SECONDS.' seconds');
            foreach ($ops->values() as $i => $op) {
                $stamp = hash('sha256', json_encode(get_object_vars($selections[$i]), JSON_THROW_ON_ERROR));
                $answer = $this->answer($op, $deadline, $stamp);
                // A cached clear/taken answer has exactly the same local post-read guard.
                foreach ($selections as $s) { $offers->validate($s); }
                if (in_array($answer, [self::CLEAR, self::UNKNOWN], true)) { continue; }
                $notice = self::notice($answer, $op->domain->domain);
                $guard = static function () use ($offers, $selections): void {
                    try { $offers->admit($selections); }
                    catch (\Throwable) { throw new \RuntimeException('Local catalogue evidence changed.'); }
                };
                $closed = app(AcquisitionRetirement::class)->closeUnpaid((int)$invoice->id, $notice, $guard);
                if (!in_array($op->domain->domain, $closed['also'], true)) { return $unavailable; }
                return [...$notice, ...$closed, 'name' => $op->domain->domain,
                    'also' => array_values(array_diff($closed['also'], [$op->domain->domain]))];
            }
            return null;
        } catch (\Throwable) { return $unavailable; }
    }

    private function orders(Invoice $invoice): Collection
    {
        if($invoice->status!=='pending'){return collect();}
        return Operation::with('domain')->where('invoice_id',$invoice->id)->whereIn('action',['register','transfer'])->whereIn('state',[...self::OPEN,'held'])
            ->orderBy('id')->get()->filter(fn($op)=>($op->attempt??[])===[]);
    }
    /** A temporary Live opt-out stops payment without cancelling an invoice or a retained attempt. */
    private static function sellingStopped(Operation $op): bool
    {
        if($op->registrar_key!=='enom'){return false;}
        $account=Account::where('server_id',$op->server_id)->first();
        if($account?->environment!=='live'){return false;}
        try{$b=AccountBindings::binding($account);return $b->fingerprint!==$op->account_fingerprint||!($op->action==='transfer'?EnomTestTransfer::accepted($b):EnomTestRegistration::sellable($b));}
        catch(\Throwable){return true;}
    }
    /** A processing row is a bank debit in flight. It is not money yet, but it can become money. */
    public static function moneyMoving(int $invoice): bool
    {return InvoiceTransaction::where('invoice_id',$invoice)->whereIn('status',[PaymentStatus::Succeeded,PaymentStatus::Processing])->exists();}
    /** One read per order per minute. Unknown is cached too, so a registrar outage costs each click nothing. */
    private function answer(Operation $op,\DateTimeImmutable $deadline, ?string $stamp = null): string
    {
        $key='domains.pay-check.'.$op->id.($stamp === null ? '' : ':'.$stamp);
        $cached=fn()=>Cache::get($key);
        $read=function()use($key,$op,$deadline,$cached):string {
            $known=$cached();if(is_string($known)){return $known;}
            $answer=$this->read($op,$deadline);Cache::put($key,$answer,self::CACHE_SECONDS);return $answer;
        };
        try{return Cache::lock($key.'.lock',self::READ_SECONDS+5)->block(self::READ_SECONDS,$read);}
        catch(\Throwable){return self::UNKNOWN;}
    }
    private function read(Operation $op,\DateTimeImmutable $deadline): string
    {
        try{
            $driver=$this->reader((int)$op->server_id);$binding=$driver->binding();
            if($binding->fingerprint!==$op->account_fingerprint){return self::UNKNOWN;}
            $query=OperationClaims::query($op,$op->domain);$started=$this->clock->now();
            $context=new RequestContext($binding,RequestOrigin::Interactive,$deadline,new RequestBudget(2),$this->clock,Runtime::limiter());
            $availability=$driver->availability($query,$context);
            if(!self::answered($binding,$availability)){return self::UNKNOWN;}
            $facts=$availability->evidence();
            if($facts->available===false){return $op->action==='transfer'?self::NOT_TRANSFERABLE:self::TAKEN;}
            if($facts->premium===true){return self::CHANGED;}
            if($facts->available===null){return self::UNKNOWN;}
            $quote=$driver->quote($query,$context);
            if(!self::answered($binding,$quote)){return self::UNKNOWN;}
            $cost=WriteMoney::cost($quote->evidence()->quote,$query,$started,$this->clock->now());
            if(!$cost){return self::UNKNOWN;}
            // Compared with what the customer pays, not the catalogue cost: a stale catalogue would stop every sale with no new price to show.
            return WriteMoney::compare($cost->amount,(string)$op->amount)>0?self::CHANGED:self::CLEAR;
        }catch(\Throwable){return self::UNKNOWN;}
    }
    private static function answered(AccountBinding $binding,DriverResult $result): bool
    {$binding->requireMatch($result->binding);return $result->disposition===Disposition::Accepted;}
    /** TEST accounts only answer quotes on the paid-preflight reader. */
    private function reader(int $serverId): RegistrarDriver
    {
        $driver=$this->bindings->reader($serverId);$binding=$driver->binding();
        return $binding->registrarKey==='enom'&&(EnomTestRegistration::enabled($binding)||EnomTestTransfer::enabled($binding))
            ?$this->bindings->registrationPreflightReader($binding):$driver;
    }
    /** The answer already stands, so payment stays stopped even when the close itself fails. */
    private function close(Invoice $invoice,Operation $op,string $why): array
    {
        $name=$op->domain->domain;$notice=self::notice($why,$name);$closed=['invoice'=>null,'also'=>[]];
        try{$closed=app(AcquisitionRetirement::class)->closeUnpaid((int)$invoice->id,$notice);}
        catch(\Throwable $e){Log::warning('Domains pay check could not close the order.',['invoice_id'=>$invoice->id,'exception_class'=>$e::class]);}
        return [...$notice,...$closed,'name'=>$name,'also'=>array_values(array_diff($closed['also'],[$name]))];
    }
    private static function returning(Invoice $invoice): ?array
    {
        $op=Operation::with('domain')->where('invoice_id',$invoice->id)->where('safe_code',UnpaidAcquisitionProof::CODE)->orderBy('id')->get()
            ->first(fn($op)=>isset($op->policy['unpaid_retirement']['notice']));
        if(!$op){return null;}
        $audit=$op->policy['unpaid_retirement'];
        $paid=InvoiceTransaction::where('invoice_id',$invoice->id)->where('status',PaymentStatus::Succeeded)->exists();
        return [...($paid?self::notice('paid_late'):$audit['notice']),'name'=>$op->domain->domain,'invoice'=>$audit['reissued_invoice']??null,'also'=>[]];
    }
    private static function send($component,array $closed): void
    {
        if($closed['why']==='unavailable'){$component->notify($closed['text'],'error');return;}
        session()->flash('domains.closed',$closed);
        $component->redirect(route('domains.domains.search',['name'=>$closed['name']]));
    }
}
