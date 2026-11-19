<?php
// Default pricing dialog: margin checks and no single own price. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
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
$dialog=static function(array $data)use($u){
    $page=Livewire\Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class)
        ->mountAction('default_pricing')->assertActionMounted('default_pricing');
    foreach([...$data,'reason'=>'Check the default pricing dialog'] as $field=>$value){
        $page->set('mountedActions.0.data.'.$field,$value);
    }
    return $page;
};
$saved=static fn()=>(new PricingRules)->defaultRule()['markup'];
$item('Item 6, no single own price for every TLD',function()use($dialog,$saved):void{
    // One own price would apply to every TLD, so the Default pricing dialog does not offer it.
    $dialog(['mode'=>'fixed','fixed_register'=>'9.99','fixed_renew'=>'9.99','fixed_transfer'=>'9.99'])
        ->callMountedAction()->assertHasActionErrors(['mode']);
    same(['markup_percent','20.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'A refused own price saves nothing');
});
$item('Direct fixed default refused',function()use($writer,$u,$now):void{
    $before=\Illuminate\Support\Facades\DB::table('settings')->orderBy('id')->get()->toJson();
    try{
        $writer->run(pricingReview($u->id),pricingForm(['mode'=>'fixed','fixed_register'=>'19.99','fixed_renew'=>'20.99','fixed_transfer'=>'21.99'],['percent'=>'4','fixed'=>'0.50']),$now);
        throw new RuntimeException('Direct fixed default was saved');
    }catch(InvalidArgumentException){}
    same($before,\Illuminate\Support\Facades\DB::table('settings')->orderBy('id')->get()->toJson(),'Refused rule and fee leave settings unchanged');
});
$item('Item 7, margin checks',function()use($dialog,$saved,$writer,$u,$now):void{
    foreach(['-5','-0',' 20','abc','501','8.125'] as $margin){
        $page=$dialog(['markup'=>$margin,'round_up'=>'none'])->assertActionDataSet(['markup'=>$margin])->callMountedAction();
        same('20.00',$saved(),'Refused margin '.var_export($margin,true).' saves nothing');
        $page->assertHasActionErrors(['markup']);
    }
    // A larger margin saved before the limit stays usable for other edits; only a new margin over the limit is refused.
    $writer->run(pricingReview($u->id),pricingForm(['mode'=>'markup_percent','markup'=>'800','round_up'=>'none']),$now);
    $dialog(['round_up'=>'whole'])->callMountedAction()->assertHasNoActionErrors();
    same(['800.00','whole'],[$saved(),(new PricingRules)->defaultRule()['round_up']],'A saved larger margin keeps working');
    $dialog(['markup'=>'801'])->callMountedAction()->assertHasActionErrors(['markup']);
    $amount=$dialog(['mode'=>'markup_amount'])->assertActionDataSet(['markup'=>null]);
    $amount->callMountedAction()->assertHasActionErrors(['markup'=>'required']);
    $amount->set('mountedActions.0.data.markup','800')->callMountedAction()->assertHasActionErrors(['markup']);
    same(['markup_percent','800.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'A saved percentage is not kept as an amount');
    $amount->set('mountedActions.0.data.markup','500')->set('mountedActions.0.data.round_up','none')
        ->callMountedAction()->assertHasNoActionErrors();
    same(['markup_amount','500.00'],[(new PricingRules)->defaultRule()['mode'],$saved()],'The largest margin saves');
});
if($failed){throw new RuntimeException(implode("\n",$failed));}
$nativeSuccessful=true;echo "Default pricing dialog passed.\n";
