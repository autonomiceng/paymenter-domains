{{-- One pager under a customer table: where you are, how many a page, and the pages. --}}
@php($links=$page->hasPages()?$page->onEachSide(1)->linkCollection()->slice(1,-1):collect())
<div class="domains-foot"><span>Showing {{ $page->firstItem() }} to {{ $page->lastItem() }} of {{ $page->total() }}</span>
@if ($page->total() > 25)<form method="GET" action="{{ $action }}" data-domains-autosubmit="auto">@foreach ($hidden as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<label class="domains-per">Per page <select name="per_page" class="domains-input">@foreach ([25,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></label></form>@endif
@if ($links->isNotEmpty())<nav class="domains-pages" aria-label="Pages">@foreach ($links as $link)@if ($link['url'])<a href="{{ $link['url'] }}" @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>@else<span>{{ $link['label'] }}</span>@endif @endforeach</nav>@endif</div>
