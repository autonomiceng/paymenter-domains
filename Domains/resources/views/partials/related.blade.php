<section aria-labelledby="related-domains-heading" class="domains-related">
<h2 id="related-domains-heading" class="domains-h2">Related to your hosting</h2><p class="domains-lede">Public registration and DNS checks for domains used by your hosting services. Public registry dates may not show recent changes. Contact your registration provider about renewal.</p>
@if($watches->isEmpty())<div class="domains-panel domains-section"><p>No domain checks yet. Domains from your active hosting services will appear here.</p></div>
@else
@foreach($watches as $summary)<section class="domains-panel domains-section"><h3 class="domains-h2 domains-wrap">{{ $summary->name }}</h3>@include('domains::partials.summary',['summaryCompact'=>true])<p><a class="domains-btn domains-btn--secondary domains-btn--sm" href="{{ $summary->urls['detail'] }}">View domain details</a></p></section>@endforeach
@if($watches->hasPages()){{ $watches->links() }}@endif
@endif
</section>
