<div>
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="$wire.closeModal()">
            <div class="w-[95vw] h-[95vh] bg-base-100 rounded-lg shadow-xl flex flex-col overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2 border-b border-base-300 shrink-0">
                    <div class="flex items-center gap-2">
                        <x-icon :name="$databaseIcon" class="w-5 h-5" />
                        <span class="text-sm text-base-content/70">{{ $databaseType }}</span>
                        <h3 class="text-sm font-bold">{{ $serverName }}</h3>
                    </div>
                    <button class="btn btn-sm btn-ghost btn-circle" @click="$wire.closeModal()">
                        <x-icon name="o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <div class="flex-1 min-h-0">
                    <iframe
                        src="{{ $adminerUrl }}"
                        class="w-full h-full border-0"
                        allow="fullscreen"
                    ></iframe>
                </div>
            </div>
        </div>
    @endif
</div>
