<div class="overflow-x-auto -mx-5 px-5">
    <x-table :headers="$headers" :rows="$rows">
        @scope('cell_env', $row)
            <span class="font-mono text-sm">{{ $row['env'] }}</span>
        @endscope

        @scope('cell_value', $row)
            <span class="font-mono text-sm text-base-content/80">{{ $row['value'] }}</span>
        @endscope

        @scope('cell_description', $row)
            <span class="text-sm text-base-content/70">{{ $row['description'] }}</span>
        @endscope
    </x-table>
</div>
