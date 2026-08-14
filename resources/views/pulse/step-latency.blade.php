<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="AI Step Latency"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.clock />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($models->isEmpty())
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
                        <x-pulse::th>Model</x-pulse::th>
                        <x-pulse::th class="text-right">Steps</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($models->take(100) as $model)
                        <tr wire:key="{{ md5($model->key) }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ md5($model->key) }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $model->key }}">
                                    {{ $model->key }}
                                </code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($model->count) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ number_format($model->avg) ?: '<1' }}</strong> ms
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ number_format($model->max) ?: '<1' }}</strong> ms
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif

        @if ($models->count() > 100)
            <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
