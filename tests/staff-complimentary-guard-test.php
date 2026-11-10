<?php
// Complimentary renewal is never offered while a renewal of the domain is open, unconfirmed or already renewed this expiry.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
[$op,$d]=$makeRenewal(placeholderComFixtureName('staff-complimentary-guard-placeholder.com'));
// Every grant the action needs, cost reading included, so each hidden check is the renewal guard and not a missing grant.
$staff=staffUser([...$all,Staff::COSTS]);Auth::setUser($staff);
$page=fn()=>Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);
$expiry=$d->fresh()->provider_expires_at->format('Y-m-d');
$set=fn(array $row)=>DB::table('domains_operations')->where('id',$op->id)->update($row);
// A paid renewal waiting to send (request #270).
$set(['state'=>'queued','base_expires_at'=>$expiry]);
expect(ViewDomain::renewalUnsettled($d->fresh()),'An open paid renewal blocks a free one');
$page()->assertActionHidden('complimentary_renewal');
// An earlier renewal whose outcome is unknown (request #65).
$set(['state'=>'uncertain']);
expect(ViewDomain::renewalUnsettled($d->fresh()),'An unconfirmed renewal blocks a free one');
$page()->assertActionHidden('complimentary_renewal');
// Done, but the saved expiry has not moved yet: the same expiry would be renewed twice.
$set(['state'=>'succeeded']);
expect(ViewDomain::renewalUnsettled($d->fresh()),'A renewal of the current expiry blocks a free one');$page()->assertActionHidden('complimentary_renewal');
// Done and the expiry moved on: the next cycle is free to renew.
DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>(new DateTimeImmutable($expiry))->modify('+1 year')->format('Y-m-d')]);
expect(!ViewDomain::renewalUnsettled($d->fresh()),'A renewal of an earlier expiry does not block');$page()->assertActionVisible('complimentary_renewal');
foreach(['rejected','cancelled'] as $closed){$set(['state'=>$closed,'base_expires_at'=>$expiry]);expect(!ViewDomain::renewalUnsettled($d->fresh()),'A '.$closed.' renewal does not block');$page()->assertActionVisible('complimentary_renewal');}
// A renewal read that fails offers no terms instead of failing the page.
$terms=new ReflectionMethod(ViewDomain::class,'complimentaryTerms');
expect($terms->invoke(null,$d->fresh())!==[],'A settled domain is offered priced terms');
$broken=new class extends \Paymenter\Extensions\Servers\Domains\Models\Domain { public function operations(){throw new RuntimeException('Synthetic renewal read failure');} };
$fresh=$d->fresh();$broken->setRawAttributes($fresh->getAttributes(),true);$broken->exists=true;$broken->setRelation('service',$fresh->service);
same([],$terms->invoke(null,$broken),'A failed renewal read offers no complimentary terms');
// The renewal check's retrying catch-all is not an account change (request #270).
$words=\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('renewal_binding_changed');
expect(!str_contains(strtolower($words),'account changed')&&str_contains($words,'retry automatically'),'A retrying renewal check says it retries, not that the account changed: '.$words);
echo "Complimentary renewal guard passed.\n";
$nativeSuccessful=true;
