<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Invoice;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
final class AccountComplimentaryRenewals
{
    /** Same frozen complimentary authorization shape consumed by RenewalFunding and RenewalExecution. */
    public static function issue(array $l,string $key,int $years,array $calendar,Clock $clock,?array $late=null):Invoice
    {
        $d=$l['domain'];$s=$l['service'];$a=ComplimentaryAccounts::authorization((int)$d->user_id);
        if(!$a||$d->price_mode!=='complimentary'){throw new \DomainException('Complimentary account setting required.');}
        $terms=DomainBilling::renewalTerms($l);
        $cost=$years===1?($l['offer']['cost']?->total??null):($terms[$years]['rule']['cost']['amount']??null);
        if($cost===null||WriteMoney::compare($cost,'0.00')<=0){throw new \DomainException('Normal registrar cost required.');}
        $cap=['amount'=>$cost,'currency'=>'USD'];
        $auth=['actor'=>$a['actor'],'reason'=>'account_complimentary','generation'=>$d->authority_generation,'expiry'=>$d->provider_expires_at->format('Y-m-d'),
            'years'=>$years,'cap'=>$cap,'at'=>$clock->now()->format(DATE_ATOM)];
        $d->update(['renewal_policy'=>[...$d->renewal_policy,'complimentary'=>$auth]]);
        $item=ComplimentaryInvoices::create((int)$d->user_id,$d->domain.' renewal, '.$years.' '.($years===1?'year':'years'),due:$calendar['due']);
        $invoice=$item->invoice;
        Operation::create(['domain_id'=>$d->id,'service_id'=>$s->id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,
            'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'operation_key'=>$key,'action'=>'renew','term'=>$years,
            'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'funding_basis'=>'complimentary','state'=>'queued','amount'=>'0.00','currency'=>'USD',
            'authorized_by'=>$a['actor'],'funding_reason'=>'account_complimentary','base_expires_at'=>$d->provider_expires_at,
            'expected_expires_at'=>$d->provider_expires_at->addYearsNoOverflow($years),'payload'=>[],
            'policy'=>['account_complimentary'=>$a,'complimentary'=>$auth,'calendar'=>$calendar,'renewal'=>['confirmed_revision'=>$d->confirmed_revision,
                'provider_domain_id'=>$d->provider_domain_id,...($late!==null?['late'=>['expiry'=>$late['expiry'],'deadline'=>$late['deadline']]]:[]),'term'=>$years,'product_id'=>(int)$s->product_id,'annual_plan_id'=>(int)$s->plan_id,
                'user_id'=>(int)$s->user_id,'order_id'=>(int)$s->order_id,'cost_cap'=>$cap,...($years!==1?['term_rule'=>$terms[$years]['rule']]:[])]]]);
        ComplimentaryInvoices::paid($invoice);return $invoice->fresh();
    }
}
