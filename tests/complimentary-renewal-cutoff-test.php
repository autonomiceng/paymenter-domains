<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Account complimentary renewal must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Invoice,Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,ComplimentaryInvoices,HoldingLocks,RenewalFunding};
$staff=commerceUser();$role=Role::create(['name'=>'Complimentary funding','permissions'=>['admin.domains.manage','admin.domains.fund','admin.domains.costs']]);$staff->update(['role_id'=>$role->id]);
$enable=fn()=>ComplimentaryAccounts::set($user->id,$staff,true,'Staff account gift',$clock);
$disable=fn()=>ComplimentaryAccounts::set($user->id,$staff,false,'Gift ended',$clock);
[$op,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('account-renew-placeholder.com'),false,1,false,$enable);
same(['complimentary','queued','0.00','paid',0.0,0],[$op->funding_basis,$op->state,$op->amount,$invoice->status,(float)$invoice->total,$invoice->transactions()->count()],'Renewal is complimentary with a paid zero receipt');
$op->update(['state'=>'held','safe_code'=>'registrar_balance_insufficient_or_unknown','policy'=>[...$op->policy,'funds_wait_until'=>$clock->now()->format(DATE_ATOM)]]);
// The same native sweep must refuse sent/uncertain attempts and damaged receipts.
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole();$virgin=$op->fresh()->getRawOriginal();
foreach([['attempt',encrypt(json_encode(['seal'=>'sealed','send_state'=>'possibly_sent']),false)],['state','uncertain']]as[$field,$value]){
    DB::table('domains_operations')->where('id',$op->id)->update([$field=>$value]);$before=$nativeMoney();
    \Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
    same($value,$op->fresh()->getRawOriginal($field),'Sent/uncertain renewal remains open: '.$field);same($before,$nativeMoney(),'Unresolved renewal moves no money');
    DB::table('domains_operations')->where('id',$op->id)->update($virgin);
}
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());same('held',$op->fresh()->state,'Damaged zero receipt cannot close');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
$before=$nativeMoney();$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole();
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same('rejected',$op->fresh()->state,'Elapsed unsent complimentary renewal closes');
same('paid',$invoice->fresh()->status,'The zero receipt stays Paid at cutoff');
same($before,$nativeMoney(),'Cutoff returns no money and invents no payment');
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());same($before,$nativeMoney(),'Repeat cutoff is money-idempotent');
echo "Complimentary renewal cutoff preserves Paid receipt\n";$nativeSuccessful=true;
