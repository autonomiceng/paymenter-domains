<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/accounts.php';
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\Auth;
use Filament\Schemas\{Schema,Components\Callout};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate};

// A stub cannot witness fresh-install routing or the four page schemas. Reuse one native app/DB,
// inspect the real schemas and render only the notice, avoiding unrelated full-page compilation.
(new Domains)->installed();
same('active',UpgradeGate::routeState()['state'],'A fresh install selects the new catalogue');
tldPanel();$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
$pages=[RegistrarSetup::class,RegistrarAccount::class,WhatYouSell::class,ListTlds::class];
$heading='Missing: an account that can sell domains.';
$step="NameSilo can't sell in the new catalogue yet. Add an Enom account to start selling.";
$check=static function(bool $visible,string $scenario,bool $retired=false)use($pages,$heading,$step):void {
    foreach($pages as $page){
        $instance=app($page);
        if($instance instanceof RegistrarAccount || $instance instanceof WhatYouSell){$instance->account=1;}
        if($retired && $page!==RegistrarSetup::class){
            // Current catalogue readers cannot render a retired installation before its checked bridge.
            $refused=false;try{$instance->content(Schema::make($instance));}
            catch(DomainException $error){same(Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE,$error->getMessage(),$scenario.': exact retired reader boundary on '.$page);$refused=true;}
            expect($refused,$scenario.': retired catalogue reader refuses on '.$page);
            same([],Paymenter\Extensions\Servers\Domains\Admin\Support\CatalogueSalesNotice::components(),$scenario.': no new-catalogue Missing notice on '.$page);
            continue;
        }
        $schema=$instance->content(Schema::make($instance));
        $notices=array_values(array_filter($schema->getComponents(),static fn($c)=>$c instanceof Callout && $c->getHeading()===$heading));
        same($visible?1:0,count($notices),$scenario.': one Missing notice on '.$page);
        foreach($notices as $notice){
            $text=html_entity_decode(strip_tags($notice->toHtml()),ENT_QUOTES|ENT_HTML5,'UTF-8');
            same(1,substr_count($text,$heading),$scenario.': the rendered Missing heading on '.$page);
            same(1,substr_count($text,$step),$scenario.': the rendered cause and next step on '.$page);
            expect(str_contains($notice->toHtml(),'domains-missing-mark'),$scenario.': the shared Missing mark on '.$page);
        }
    }
};
// With no accounts, the notice keeps the one-click path to connect the first registrar.
$empty=app(WhatYouSell::class);
$notices=$empty->content(Schema::make($empty))->getComponents();
same(1,count($notices),'Zero accounts shows one notice');
same($heading,$notices[0]->getHeading(),'Zero accounts shows the Missing sales account notice');
$html=$notices[0]->toHtml();
expect(str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'),$step),'Zero accounts names the next step');
$dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
$links=(new DOMXPath($dom))->query('//a[normalize-space(.)="Open Registrars"]');
same(1,$links->length,'Zero accounts keeps the Open Registrars button');
same(RegistrarSetup::getUrl(),$links->item(0)->getAttribute('href'),'Open Registrars points to account setup');

fixtureAccount(1,'namesilo','synthetic-namesilo');
$check(false,'Connected NameSilo supplies supported sales');
// Both connected registrar protocols supply sales; prices and Orders have separate setup guidance.
fixtureAccount(2,'enom','synthetic-enom');
$check(false,'Enom present');
Server::whereKey(2)->update(['enabled'=>false]);
$check(false,'Enabled NameSilo still supplies sales with Enom disabled');
Server::whereKey(1)->update(['enabled'=>false]);
$check(true,'Neither disabled registrar can supply sales');
// An unconverted installation retains legacy routing and must never get new-catalogue guidance.
Setting::where('key',UpgradeGate::KEY)->whereNull('settingable_type')->whereNull('settingable_id')->delete();
CallerRouting::reset();
same('legacy',UpgradeGate::routeState()['state'],'The fixture now represents an unconverted install');
$check(false,'Old mode',true);
echo "Fresh NameSilo Missing notice passed.\n";
$nativeSuccessful=true;
