<x-modal wire:model="showTokenModal" :title="__('Agent Token')" class="backdrop-blur" persistent>
    <x-alert class="alert-warning mb-4" icon="o-exclamation-triangle">
        {{ __('Copy this token now. It will not be shown again.') }}
    </x-alert>

    <div class="mockup-code text-sm">
        <pre><code class="break-all">{{ $newToken }}</code></pre>
    </div>

    <div class="mt-4 p-4 rounded-lg bg-base-200 space-y-2">
        <p class="text-sm font-medium">{{ __('Configure the agent with these environment variables:') }}</p>
        <div class="mockup-code text-sm">
            <pre><code>DATABASEMENT_URL={{ config('app.url') }}</code></pre>
            <pre><code>DATABASEMENT_AGENT_TOKEN={{ $newToken }}</code></pre>
        </div>
    </div>

    <x-slot:actions>
        <x-button :label="__('Done')" class="btn-primary" wire:click="closeTokenModal" />
    </x-slot:actions>
</x-modal>
