<?php
// Synthetic NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, RenewalAcceptance, RenewalAdmission, RenewalAuthority};
$op=$prepare('full'); $r=$reconcile->run($op->id); $completed=$r;
same('succeeded',$r->state,'Exact status, Active, flags, unique charge and full added year complete');
same('99.00',$r->cost['actual']['amount'],'Original transfer charge committed'); same('9001',$r->provider_order_id,'History ID retained without invented original ID');
same('2028-01-01',$r->domain->provider_expires_at->format('Y-m-d'),'Full year from immutable baseline'); same(1,$r->domain->confirmed_revision,'Revision committed');
same('active',App\Models\Service::find($r->service_id)->status,'Native service activated'); same(null,App\Models\Service::find($r->service_id)->expires_at,'Native expiry remains null');
same(['ns1.provider.example','ns2.provider.example'],$r->domain->fresh()->observation['nameservers']['nameservers']??null,'NameSilo domain-info values are saved on completed transfer');
same('observed',$r->domain->fresh()->observation['nameservers']['state']??null,'NameSilo transfer nameservers retain observed provenance');
expect(RenewalAuthority::completed($r,$r->domain,$binding),'Transfer anchor accepted');
$calls=$observe->calls; $reconcile->run($r->id); same($calls,$observe->calls,'Repeated completed reconciliation performs no I/O');
$op=$prepare('no-status-expiry'); $observe->expiration=[]; same('succeeded',$reconcile->run($op->id)->state,'Optional expiration absent uses proven domain expiry');
$op=$prepare('missing-nameservers'); $observe->fault='missing_nameservers'; $r=$reconcile->run($op->id);
same('succeeded',$r->state,'Missing nameserver readback does not block transfer completion');
same(['ns1.provider.example','ns2.provider.example'],$r->domain->fresh()->observation['nameservers']['nameservers']??null,'Submitted nameservers remain visible without readback');
same('requested',$r->domain->fresh()->observation['nameservers']['state']??null,'NameSilo transfer fallback stays unconfirmed');
$unresolved(['inactive','auto','privacy','short','owner','nocharge','duplicate','late','credited','unknowncharge','paged','nohistory','lag']);
// Slice25 accepts the no-ID anchor and still requires immutable ordinary acquisition proof.
$observe->name=$completed->domain->domain; $observe->fault=''; $authority=new RenewalAuthority($readBindings,$clock);
$request=new RenewalRequest($completed->domain_id,$completed->authority_generation,$completed->domain->confirmed_revision,'2028-01-01',null);
$admit=new RenewalAdmission($authority,$readBindings,new RenewalAcceptance($binding,true,true,25),$clock);
Cache::flush(); same(RenewalCode::Admitted,$admit->check($request)->code,'NameSilo transfer anchor admits ordinary renewal');
$saved=$completed->fresh()->getRawOriginal('attempt'); $a=$completed->fresh()->attempt; unset($a['transfer_admission']['premium']); $completed->fresh()->forceFill(['attempt'=>$a])->saveQuietly();
Cache::flush(); same(RenewalCode::Cost,$admit->check($request)->code,'No ordinary proof means Cost, never inferred from Active');
commerceReject(fn()=>$completed->fresh()->update(['attempt'=>$completed->attempt])); DB::table('domains_operations')->where('id',$completed->id)->update(['attempt'=>$saved]);
App\Models\Server::find($completed->server_id)->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$billing=new DomainBilling($bindings,$guard,$clock); $invoice=$billing->invoice($completed->domain_id,$user);
$renew=Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();
same('2028-01-01',$renew->base_expires_at->format('Y-m-d'),'Slice24 bills from NameSilo transfer anchor'); same('2029-01-01',$renew->expected_expires_at->format('Y-m-d'),'Fixed full renewal term');
same($invoice->id,$billing->invoice($completed->domain_id,$user)->id,'Renewal invoice idempotent'); same(null,$invoice->items->sole()->reference_id,'No native renewal dispatch');
echo "NameSilo transfer completion, financial gates and billing anchors passed.\n"; $nativeSuccessful=true;
