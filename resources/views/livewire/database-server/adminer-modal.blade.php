<div>
    @if($showModal && $isPromo)
        <x-modal wire:model="showModal" :title="__('Enable the Database Browser')" class="backdrop-blur">
            <div class="space-y-4">
                <x-alert class="alert-info" icon="o-information-circle">
                    <div>
                        <span class="font-bold">{{ __('Feature not enabled') }}</span>
                        <p class="text-sm mt-1">
                            {{ __('The built-in Adminer database browser lets you view and edit data directly from Databasement, without leaving the app.') }}
                        </p>
                    </div>
                </x-alert>

                <div class="p-4 border rounded-lg bg-base-200 border-base-300 space-y-2">
                    <div class="text-sm font-semibold">{{ __('How to enable it') }}</div>
                    <ol class="list-decimal list-inside text-sm space-y-1 opacity-80">
                        <li>{{ __('Go to Configuration › Application.') }}</li>
                        <li>{{ __('Toggle "Database Browser" on.') }}</li>
                        <li>{{ __('Pick the minimum role allowed to browse.') }}</li>
                    </ol>
                </div>
            </div>

            <x-slot:actions>
                <x-button :label="__('Cancel')" wire:click="closeModal" class="btn-ghost" />
                <x-button :label="__('Open Configuration')" icon="o-cog-6-tooth"
                          link="{{ route('configuration.application') }}" wire:navigate
                          class="btn-primary" />
            </x-slot:actions>
        </x-modal>
    @elseif($showModal)
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
