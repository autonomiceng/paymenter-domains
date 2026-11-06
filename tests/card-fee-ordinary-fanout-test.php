<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,StaffSettingsReview as Review,StaffSettingsWrites};
// The installed staff writer, native price rows and publication revisions are the affected boundary.
extract(commerceFixture(true));tldPanel();
$actor=staffUser([...array_keys(Staff::permissions()),...array_map(Review::permission(...),array_keys(Review::ACTIONS))]);Auth::setUser($actor);
$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);
$pricesBefore=Price::orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all();
$offersBefore=Tld::orderBy('id')->get()->map(fn($t)=>[$t->id,$t->catalogue_revision,$t->catalogue_published_revision])->all();
$result=$writer->run(pricingReview($actor->id),pricingForm(fee:['percent'=>'3.10','fixed'=>'0.40']),$now);
same(['published'=>0,'failed'=>[],'skipped'=>[]],$result,'A fee-only edit has no ordinary-price publication targets: '.json_encode($result));
same(['percent'=>'3.10','fixed'=>'0.40'],CardFee::read(),'The reviewed fee is saved');
$audit=collect(Review::historyRows(App\Models\Server::findOrFail($tlds[0]->server_id)))->where('phase','completed')->where('action',Review::ACTIONS['default_pricing'])->last();
same(CardFee::DEFAULT,$audit['before']['card_fee']??null,'The completed pricing audit retains the previous card fee');
same(['percent'=>'3.10','fixed'=>'0.40'],$audit['after']['card_fee']??null,'The completed pricing audit retains the reviewed card fee');
expect(is_array($audit['before']['rule']??null)&&is_array($audit['after']['rule']??null),'The completed pricing audit retains the actual rules');
same($pricesBefore,Price::orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all(),'Ordinary native prices and revisions stay unchanged');
same($offersBefore,Tld::orderBy('id')->get()->map(fn($t)=>[$t->id,$t->catalogue_revision,$t->catalogue_published_revision])->all(),'Ordinary publication authority stays current after a fee-only edit');
$nativeSuccessful=true;echo "A fee-only staff edit preserves ordinary published offers.\n";
