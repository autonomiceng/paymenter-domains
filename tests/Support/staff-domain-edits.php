<?php
// Staff edits on the domain page: the owner's form path and checks, credited to the staff member.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/management.php';
require __DIR__.'/owner-pages.php';
require __DIR__.'/staff-panel.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
staffPanel();storefrontViews();
[$d,$driver]=$holding(placeholderComFixtureName('staff-edit-placeholder.com'));
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,$owners);app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);$staff->update(['first_name'=>'Sam','last_name'=>'Support']);$actor=$staff->fresh();Auth::setUser($actor);
$view=static fn()=>Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$open=static fn(string $row,string $action)=>$view()->mountAction(TestAction::make('edit_'.$action)->schemaComponent('relationManagerTabs.sheet','content'));
// Submit the mounted form fields and action in the same Livewire request as the browser.
$edit = static function (string $row, string $action, array $data = []) use ($open) {
    $page = $open($row, $action);
    $schema = $page->instance()->getMountedActionSchemaName();
    $page->assertSchemaExists($schema);
    $path = $page->instance()->{$schema}->getStatePath();
    $updates = [];
    foreach ($data as $field => $value) {
        $updates[$path.'.'.$field] = $value;
    }

    return $page->update(calls: [['method' => 'callMountedAction', 'params' => [[]], 'path' => '']], updates: $updates);
};
$last=static fn(string $action)=>$d->operations()->where('action',$action)->orderByDesc('id')->first();
$finish=static function(Operation $op)use($management,$driver):void{Illuminate\Support\Facades\Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Staff '.$op->action.' uses the same queue and readback');};
