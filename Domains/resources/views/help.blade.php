@component('layouts.app', ['title' => 'Domain help', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<a class="domains-back" href="{{ route('domains.domains.index') }}">Domains</a>
<h1 class="domains-title">Domain help</h1>
<nav class="domains-panel domains-section" aria-labelledby="help-contents"><h2 id="help-contents" class="domains-h2">Contents</h2>
<ul class="domains-help-toc">@foreach ($anchors as $id => $title)<li><a class="domains-a" href="#{{ $id }}">{{ $title }}</a></li>@endforeach</ul></nav>
<article class="domains-panel domains-section domains-prose">{!! $html !!}</article>
</div></div>
@endcomponent
