{{-- History as a trace: newest work first, the mark and the word are the step's State for customers (customer.trace). --}}
@php
    $values = \Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::class;
    $rows = [...array_map(fn ($row) => [...$row, 'moment' => true], $domain->progress), ...$domain->history];
    $all = request()->query('history') === 'all';
    $steps = array_map(function ($step) use ($values) {
        $word = $step['state'] ?? $step['status'];
        $note = $step['note'] ?? null;
        // A failed change the customer cannot finish alone says how to get it done.
        if ($note !== null && $word === 'Failed' && !($step['noteComplete'] ?? false)) {
            $help = ($step['retry'] ?? false)
                ? ' Try again. '.view('domains::partials.contact-support', ['before' => 'Still stuck? '])->render()
                : ' '.view('domains::partials.contact-support', ['after' => ' to make this change.'])->render();
            $note = new \Illuminate\Support\HtmlString(e($note).$help);
        }
        return [
            'label' => ($step['url'] ?? null) ? 'Invoice #'.$step['number'] : ($step['label'] === 'WHOIS privacy' && !\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN ? 'Domain change' : $step['label']),
            'url' => $step['url'] ?? null, 'state' => $values::step($word), 'word' => $word, 'by' => $step['by'] ?? null,
            'reason' => $note, 'date' => $step['date'] ?? null, 'moment' => $step['moment'] ?? false,
        ];
    }, $rows);
    $empty = in_array($domain->order['kind'] ?? null, ['registration', 'transfer'], true) && ($domain->order['state'] ?? null) === 'paid' ? ucfirst($domain->order['kind']).' is in progress.' : 'No changes yet.';
@endphp
<x-domains::customer.section id="history" title="History">
<x-slot:link>@if (count($steps) > 5 && !$all)<a class="domains-a" href="{{ $domain->actions['show'] }}?history=all#history">View all {{ count($steps) }}</a>@endif</x-slot:link>
@if (!$steps)<x-domains::customer.list-empty :text="$empty" />@else<x-domains::customer.trace :steps="$all ? $steps : array_slice($steps, 0, 5)" />@endif
</x-domains::customer.section>
