<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
/** Privileged fixture setup; the site settings action is exercised separately through Livewire. */
function siteDnsFixture(?int $id): void
{
    \Illuminate\Support\Facades\DB::transaction(static fn()=>\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::KEY,$id?(string)$id:''));
}
