@component('layouts.app', ['title' => 'Domain help', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<x-domains::customer.head title="Domain help" :crumbs="[['Domains', route('domains.domains.index')], ['Domain help', null]]" />
{{-- One sheet (DESIGN.md P-LAY-1): the contents line, then every topic. --}}
<article class="domains-panel domains-section domains-prose" data-piece="sheet"><nav class="domains-help-nav" aria-labelledby="help-contents"><h2 id="help-contents" class="domains-h2">Contents</h2>
<ul class="domains-help-toc">@foreach ($anchors as $id => $title)<li><a class="domains-a" href="#{{ $id }}">{{ $title }}</a></li>@endforeach</ul></nav>
{!! $html !!}</article>
</div></div>
@endcomponent
