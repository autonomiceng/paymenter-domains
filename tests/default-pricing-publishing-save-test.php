<?php
// Saving Default pricing while the last save is still publishing is not someone else's change. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{BusinessSetting,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
$f=commerceFixture(true);extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$permissions[]=Staff::COSTS;$u=staffUser($permissions);Auth::setUser($u);$commands=new \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands(
    new \Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$writer=new class($native,$sellers,$bindings,true) extends StaffSettingsWrites {
    public \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands $commands;
    protected function catalogue():?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands{return $this->commands;}
};$writer->commands=$commands;
$writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'20','round_up'=>'none']),$now);

// A save made while the previous save is still publishing is not someone else's change.
// The previous save committed its new rule and marked each TLD for publishing; its publishing loop has not reached .com yet.
DB::transaction(function()use($t):void{
    BusinessSetting::saveLocked(PricingRules::KEY,['mode'=>'markup_percent','markup'=>'60.00','round_up'=>'none','fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]]);
    Tld::whereKey($t->id)->increment('catalogue_revision');
});
$retained=Tld::findOrFail($t->id)->getRawOriginal('catalogue_retained_annual_retail');
$open=pricingReview($u->id);
$commands->publish($t->id);
expect(Tld::findOrFail($t->id)->getRawOriginal('catalogue_retained_annual_retail')!==$retained,'Fixture: publishing changed the kept renewal price');
$summary=$writer->run($open,pricingForm(['mode'=>'markup_percent','markup'=>'25','round_up'=>'none']),$now);
same('25.00',(new PricingRules)->defaultRule()['markup'],'The save made during publishing is kept: '.json_encode($summary));

$nativeSuccessful=true;echo "Default pricing save during publishing passed.\n";
