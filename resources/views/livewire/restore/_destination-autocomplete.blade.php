{{--
    Type-ahead for the destination database name. Requires the host component to
    use App\Livewire\Concerns\InteractsWithTargetDatabases (provides
    $existingDatabases, $this->filteredDatabases and selectDatabase) and a
    wire-bound $schemaName.

    Params: $label, $placeholder
--}}
<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <x-input
        wire:model.live.debounce.200ms="schemaName"
        :label="$label"
        :placeholder="$placeholder"
        @focus="open = true"
        @keydown.escape="open = false"
        autocomplete="off"
    />
    @error('schemaName')
    <p class="text-error text-sm mt-1">{{ $message }}</p>
    @enderror

    @if(count($this->filteredDatabases) > 0)
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-lg shadow-lg max-h-48 overflow-y-auto"
        >
            @foreach($this->filteredDatabases as $database)
                <div
                    wire:click="selectDatabase({{ \Illuminate\Support\Js::from($database) }})"
                    @click="open = false"
                    class="px-3 py-2 cursor-pointer hover:bg-base-200 text-sm {{ $schemaName === $database ? 'bg-primary/10 font-medium' : '' }}"
                >
                    {{ $database }}
                </div>
            @endforeach
        </div>
    @endif
</div>
