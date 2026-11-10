<?php
// Default pricing dialog: margin checks, no single own price, and what a below-cost price does. Each item reports on its own. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Services\{PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
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

$failed=[];
$item=static function(string $name,callable $check)use(&$failed):void{try{$check();}catch(Throwable $e){$failed[]=$name.': '.$e->getMessage();}};
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));pinStaffClock($now);
$dialog=static fn(array $data)=>Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class)
    ->mountAction('default_pricing')->assertActionMounted('default_pricing')->setActionData([...$data,'reason'=>'Check the default pricing dialog'])->callMountedAction();
$saved=static fn()=>(new PricingRules)->defaultRule()['markup'];
$item('Item 6, no single own price for every TLD',function()use($dialog,$saved):void{
    // One own price would apply to every TLD, so the Default pricing dialog does not offer it.
    $dialog(['mode'=>'fixed','fixed_register'=>'9.99','fixed_renew'=>'9.99','fixed_transfer'=>'9.99'])->assertHasActionErrors(['mode']);
    same(['markup_percent','20.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'A refused own price saves nothing');
});
$item('Direct fixed default refused',function()use($writer,$u,$now):void{
    $before=\Illuminate\Support\Facades\DB::table('settings')->orderBy('id')->get()->toJson();
    try{
        $writer->run(pricingReview($u->id),pricingForm(['mode'=>'fixed','fixed_register'=>'19.99','fixed_renew'=>'20.99','fixed_transfer'=>'21.99'],['percent'=>'4','fixed'=>'0.50']),$now);
        throw new RuntimeException('Direct fixed default was saved');
    }catch(InvalidArgumentException $e){same('Set an own price on each TLD instead.', $e->getMessage(),'Writer refuses a fixed default');}
    same($before,\Illuminate\Support\Facades\DB::table('settings')->orderBy('id')->get()->toJson(),'Refused rule and fee leave settings unchanged');
});
$item('Item 7, margin checks',function()use($dialog,$saved,$writer,$u,$now):void{
    foreach(['-5'=>'Enter 0 or more.','-0'=>'Enter 0 or more.',' 20'=>'Enter a number.','abc'=>'Enter a number.','1000'=>'Enter 500 or less.','8.125'=>'Use at most two decimal places.'] as $margin=>$message){
        $dialog(['mode'=>'markup_percent','markup'=>(string)$margin,'round_up'=>'none'])->assertHasActionErrors(['markup'=>$message]);
    }
    same('20.00',$saved(),'Refused margins save nothing');
    // A larger margin saved before the limit stays usable for other edits; only a new margin over the limit is refused.
    $writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'800','round_up'=>'none']),$now);
    $dialog(['round_up'=>'whole'])->assertHasNoActionErrors();
    same(['800.00','whole'],[$saved(),(new PricingRules)->defaultRule()['round_up']],'A saved larger margin keeps working');
    $dialog(['markup'=>'801'])->assertHasActionErrors(['markup'=>'Enter 500 or less.']);
    // The saved 800 is a percentage; as an amount to add it is a new margin over the limit.
    $dialog(['mode'=>'markup_amount'])->assertHasActionErrors(['markup'=>'Enter 500 or less.']);
    same(['markup_percent','800.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'A saved percentage is not kept as an amount');
    $dialog(['mode'=>'markup_amount','markup'=>'500','round_up'=>'none'])->assertHasNoActionErrors();
    same(['markup_amount','500.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'The largest margin saves');
});
$item('Item 2, below-cost words',function():void{
    // The TLD price dialog says what happens to a price below cost; saving raises no staff alert.
    $words=SettingsActions::description('override');
    expect(!str_contains($words,'alert staff'),'No claim that saving alerts staff');
    expect(str_contains($words,'Registrations and transfers below cost are not sold')&&str_contains($words,'each one adds an alert to its domain'),'Below-cost words say what happens');
    // The TLD's status reads the one-year registration only, so the words name where each term's reason shows.
    expect(str_contains($words,'Edit prices and the price list show why for each term')&&!str_contains($words,'the TLD shows why'),'Below-cost words name where the reason shows');
});
if($failed){throw new RuntimeException(implode("\n",$failed));}
$nativeSuccessful=true;echo "Default pricing dialog passed.\n";
