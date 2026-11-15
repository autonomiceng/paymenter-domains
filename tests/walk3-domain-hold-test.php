<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/Support/staff-domain-edits.php';
Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$d->server_id)->update(['processing_enabled'=>false]);
$edits=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainEditActions::for($d->fresh());
same(null,$edits['request'],'An active domain has no held request');
same(null,$edits['note'],'Orders off disables edit controls without inventing a current hold');
same([],$edits['rows'],'Orders off still stops changes');
$nativeSuccessful=true;
