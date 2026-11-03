<span data-price-omission>
    @if($warning['cause'] === 'custom_rule')
        <span data-omitted-tld>{{ $warning['tlds'][0] }}</span> on {{ $warning['account'] }} left out: custom pricing can't be read.
        To fix, open the {{ $warning['tlds'][0] }} TLD page and use Edit prices.
    @else
        {{ $warning['cause'] === 'card_fee' ? "Card fee can't be read." : "Default pricing can't be read." }}
        {{ count($warning['tlds']) }} {{ count($warning['tlds']) === 1 ? 'TLD' : 'TLDs' }} left out: @foreach($warning['tlds'] as $tld)<span data-omitted-tld>{{ $tld }}</span>{{ $loop->last ? '.' : ', ' }}@endforeach
        To fix, use Default pricing at the top of this page.
    @endif
</span>
