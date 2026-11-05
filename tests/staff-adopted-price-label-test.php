<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A real imported holding and encrypted observation must reach the native staff fact and page.
require __DIR__.'/Support/adoption-confirm.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\Adoption;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
extract(adoptionCommitFixture());
$r=$reader->preview($target,$context);$a=Adoption::findOrFail($r->previewId);
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
staffPanel();Auth::login($staff);
// AdoptionCommit persists this same retail marker in adoption_review.price.
$observation=$d->observation??[];
$d->update(['observation'=>[...$observation,'adoption_review'=>['price'=>'retained_renewal']]]);
$price=DomainFacts::for($d->fresh())['Renewal price'];
same(['Standard pricing',null],[$price['sub'],$price['tone']],'An adopted retained renewal price must not claim Cost missing');
Livewire\Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertDontSee('Cost missing');
// A real price/cost review marker still needs its warning.
$d->update(['observation'=>[...$observation,'adoption_review'=>['price'=>'below_cost']]]);
same(['Below cost','danger'],(fn($p)=>[$p['sub'],$p['tone']])(DomainFacts::for($d->fresh())['Renewal price']),'Adopted below-cost warning stays visible');
$nativeSuccessful=true;echo "Adopted retained-price marker is informational in staff facts and page.\n";
