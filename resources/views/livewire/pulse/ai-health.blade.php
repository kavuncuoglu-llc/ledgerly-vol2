<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI time to first token and breaker trips"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($ttft->isEmpty() && $trips->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Feature</x-pulse::th>
                        <x-pulse::th class="text-right">TTFT avg</x-pulse::th>
                        <x-pulse::th class="text-right">TTFT max</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($ttft as $row)
                        <tr wire:key="{{ $row->feature }}-ttft">
                            <x-pulse::td><code class="text-xs">{{ $row->feature }}</code></x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->avg) }} ms</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->max) }} ms</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($trips->isNotEmpty())
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Breaker trips:
                    @foreach ($trips as $trip)
                        <code>{{ $trip->provider }}</code> {{ $trip->count }}@if (! $loop->last), @endif
                    @endforeach
                </p>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
