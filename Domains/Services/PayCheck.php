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
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Live name check when a customer starts to pay, before any money moves. Scoped to the native invoice pay call.
 * A read that fails lets payment go: the after-payment preflight still guards the send. */
final class PayCheck
{
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
    /** Closes the invoice when a name can no longer be sold as ordered. Null lets payment go. */
    public function stop(Invoice $invoice): ?array
    {
        try{
            $deadline=$this->clock->now()->modify('+'.self::READ_SECONDS.' seconds');
            foreach($this->orders($invoice) as $op){
                $answer=$this->answer($op,$deadline);
                if(!in_array($answer,[self::CLEAR,self::UNKNOWN],true)){return $this->close($invoice,$op,$answer);}
            }
        }catch(\Throwable $e){Log::warning('Domains pay check skipped.',['invoice_id'=>$invoice->id,'exception_class'=>$e::class]);}
        return null;
    }
    private function orders(Invoice $invoice): Collection
    {
        if($invoice->status!=='pending'||self::moneyMoving((int)$invoice->id)){return collect();}
        return Operation::with('domain')->where('invoice_id',$invoice->id)->whereIn('action',['register','transfer'])->whereIn('state',self::OPEN)
            ->orderBy('id')->get()->filter(fn($op)=>($op->attempt??[])===[]);
    }
    /** A processing row is a bank debit in flight. It is not money yet, but it can become money. */
    public static function moneyMoving(int $invoice): bool
    {return InvoiceTransaction::where('invoice_id',$invoice)->whereIn('status',[PaymentStatus::Succeeded,PaymentStatus::Processing])->exists();}
    /** One read per order per minute. Unknown is cached too, so a registrar outage costs each click nothing. */
    private function answer(Operation $op,\DateTimeImmutable $deadline): string
    {
        $key='domains.pay-check.'.$op->id;
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
        session()->flash('domains.closed',$closed);
        $component->redirect(route('domains.domains.search',['name'=>$closed['name']]));
    }
}
