<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';require __DIR__.'/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{ReminderSchedules,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$u=staffUser([Staff::VIEW,Staff::POLICY]);Auth::setUser($u);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
config(['app.debug'=>true]);
ReminderSchedules::enroll($server);$query=DB::table('domains_reminder_schedules')->where('server_id',$server);
$original=(array)(clone $query)->sole();$name=setupAction('reminders',$server);
$placeholder=static fn($page,string $key)=>$page->instance()->getSchema('mountedActionSchema0')->getComponent(fn($field)=>$field instanceof Filament\Forms\Components\TextInput&&$field->getName()===$key)->getPlaceholder();
