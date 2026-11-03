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
fixtureAccount(1,'namesilo','synthetic-namesilo');
tldPanel();$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
$pages=[RegistrarSetup::class,RegistrarAccount::class,WhatYouSell::class,ListTlds::class];
$heading='Missing: an account that can sell domains.';
$step="NameSilo can't sell in the new catalogue yet. Add an Enom account to start selling.";
$check=static function(bool $visible,string $scenario)use($pages,$heading,$step):void {
    foreach($pages as $page){
        $instance=app($page);
        if($instance instanceof RegistrarAccount || $instance instanceof WhatYouSell){$instance->account=1;}
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
$check(true,'NameSilo-only fresh install');
// A connected Enom account supplies the protocol; prices and Orders have their own setup guidance.
fixtureAccount(2,'enom','synthetic-enom');
$check(false,'Enom present');
Server::whereKey(2)->update(['enabled'=>false]);
$check(true,'Disabled Enom cannot supply sales');
// An unconverted installation retains legacy routing and must never get new-catalogue guidance.
Setting::where('key',UpgradeGate::KEY)->whereNull('settingable_type')->whereNull('settingable_id')->delete();
CallerRouting::reset();
same('legacy',UpgradeGate::routeState()['state'],'The fixture now represents an unconverted install');
$check(false,'Old mode');
echo "Fresh NameSilo Missing notice passed.\n";
$nativeSuccessful=true;
