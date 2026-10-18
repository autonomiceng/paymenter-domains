<?php
// Pinned native mail shell and fakes only; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Mail,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationSchema,ReminderSchema,ReminderTemplates};
ReminderSchema::install();ObservationSchema::install();ReminderTemplates::ensure();
app('view')->addNamespace('domains',dirname(__DIR__,2).'/Domains/resources/views');
config(['settings.mail_disable'=>false,'settings.logo'=>null]);
foreach(['mail_header','mail_footer','mail_css'] as $key){config(['settings.'.$key=>file_get_contents($fixture.'/database/seeders/data/'.$key)]);}
Mail::fake();Queue::fake();
set_exception_handler(static function(\Throwable $e):void {fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
