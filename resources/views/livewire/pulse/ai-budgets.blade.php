<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="AI budgets" details="live">
        <x-slot:icon>
            <x-pulse::icons.sparkles />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        <div class="grid gap-3 text-sm text-gray-700 dark:text-gray-300">
            <div>
                App this hour: <strong>${{ number_format($hourSpent, 2) }}</strong> of ${{ number_format($hourLimit, 0) }}
                ({{ $hourLimit > 0 ? round($hourSpent / $hourLimit * 100) : 0 }}%)
            </div>

            <div>
                Unattributed spend today: <strong>{{ round($unattributedShare * 100) }}%</strong>
            </div>

            @forelse ($kills as $scope => $kill)
                <div class="text-red-600 dark:text-red-400">Kill switch ON: <code>{{ $scope }}</code> since {{ $kill['at'] }}: {{ $kill['reason'] }}</div>
            @empty
                <div>No kill switch is on.</div>
            @endforelse

            @forelse ($breakers as $scope => $state)
                <div>Breaker {{ strtoupper($state['state']) }}: <code>{{ $scope }}</code> until {{ $state['until'] }}</div>
            @empty
                <div>No spend breaker is open.</div>
            @endforelse
        </div>
    </x-pulse::scroll>
</x-pulse::card>
