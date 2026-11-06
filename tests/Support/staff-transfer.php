<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/transfer-fixture.php';
require __DIR__.'/staff-panel.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations,StaffOperationWrites,TransferInputs};
staffPanel();app()->instance(Clock::class,$clock);app()->instance(Refunds::class,$refunds);
$perms=[Staff::VIEW,'admin.domains.manage','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS)as$action){$perms[]=StaffOperationReview::permission($action);}
$staff=staffUser($perms);$service=new StaffOperations($clock);
$run=static function($op,$action)use($service,$staff,$clock){$op=$op->fresh();$key=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op,$op->domain),$clock);
    $service->run($staff,$op->domain_id,$op->id,$action,$key,'Reviewed exact transfer evidence');};
Illuminate\Support\Facades\Auth::setUser($staff);
