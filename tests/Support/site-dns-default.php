<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
/** Privileged fixture setup; the site settings action is exercised separately through Livewire. */
function siteDnsFixture(?int $id): void
{
    App\Models\Setting::updateOrCreate(['key'=>Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::KEY,
        'settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>$id?(string)$id:'']);
}
