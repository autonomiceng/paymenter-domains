<span data-price-omission>
    @if($warning['cause'] === 'custom_rule')
        <span data-omitted-tld>{{ $warning['tlds'][0] }}</span> on {{ $warning['account'] }} has prices left out: its custom pricing can't be read.
        Open {{ $warning['tlds'][0] }} and use Edit prices.
    @else
        {{ $warning['cause'] === 'card_fee' ? "The card fee can't be read" : "Default pricing can't be read" }}, so prices are left out for: @foreach($warning['tlds'] as $tld)<span data-omitted-tld>{{ $tld }}</span>{{ $loop->last ? '.' : ', ' }}@endforeach
        Fix it in Default pricing at the top of this page.
    @endif
</span>
