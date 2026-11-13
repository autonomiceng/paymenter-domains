{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A page title (Panel\Head) with its Demo or Test tag. --}}
<span class="domains-panel-head-title" data-piece="head-title">{{ $domain ? \Paymenter\Extensions\Servers\Domains\Presentation\DomainName::html($title) : $title }}</span>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($demo, $test, inline: true) }}
