<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI tokens and cost"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="per feature, past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($features->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Feature</x-pulse::th>
                        <x-pulse::th class="text-right">Tokens</x-pulse::th>
                        <x-pulse::th class="text-right">Cached</x-pulse::th>
                        <x-pulse::th class="text-right">Cost</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($features as $feature)
                        <tr wire:key="{{ $feature->feature }}-row">
                            <x-pulse::td><code class="text-xs">{{ $feature->feature }}</code></x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($feature->tokens) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ $feature->cachedShare === null ? '—' : round($feature->cachedShare * 100).'%' }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">${{ number_format($feature->costUsd, 4) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
