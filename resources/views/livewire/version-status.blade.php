<div class="inline-flex">
    {{-- Footer trigger --}}
    @if($latestVersion && $currentVersion && $latestVersion !== $currentVersion && $isAppVersion)
        {{-- Update available — eye-catching pill --}}
        <button
            wire:click="open"
            class="inline-flex items-center gap-1.5 cursor-pointer rounded-full px-2 py-0.5 bg-warning/10 border border-warning/20 text-warning text-sm font-medium hover:bg-warning/20 hover:border-warning/35 transition-all"
            title="{{ $latestVersion }} {{ __('available') }}"
        >
            <span class="relative flex h-1.5 w-1.5 shrink-0">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-warning opacity-60"></span>
                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-warning"></span>
            </span>
            {{ $latestVersion }} {{ __('available') }}
        </button>
    @elseif($currentVersion)
        {{-- Up to date or no latest info — subtle version with green dot --}}
        <button
            wire:click="open"
            class="inline-flex items-center gap-1.5 text-sm text-base-content/60 hover:text-base-content transition-colors cursor-pointer"
        >
            @if($latestVersion === $currentVersion)
                <span class="flex h-1.5 w-1.5 shrink-0">
                    <span class="block h-full w-full rounded-full bg-success opacity-70"></span>
                </span>
            @endif
            <span class="font-mono">{{ $currentVersion }}</span>
        </button>
    @else
        {{-- No version info — plain link --}}
        <button
            wire:click="open"
            class="link link-hover text-sm text-base-content/60"
        >
            {{ __('How to update?') }}
        </button>
    @endif

    {{-- Modal --}}
    <x-modal wire:model="showModal" :title="__('How to update?')" class="backdrop-blur" box-class="max-w-2xl">

        {{-- Version status --}}
        @if($latestVersion && $currentVersion && $latestVersion === $currentVersion)
            <x-alert icon="o-check-circle" class="alert-success mb-4">
                {{ __('You are running the latest version') }}
                <a href="{{ $releaseUrl }}" target="_blank" rel="noopener" class="font-mono font-semibold link">{{ $latestVersion }}</a>
            </x-alert>
        @elseif($latestVersion && $currentVersion)
            <x-alert icon="o-arrow-path" class="alert-warning mb-4">
                <span class="inline-flex items-center gap-1.5 flex-wrap">
                    {{ __('Update available:') }}
                    <span class="font-mono">{{ $currentVersion }}</span>
                    <x-icon name="o-arrow-right" class="w-3.5 h-3.5" />
                    <a href="{{ $releaseUrl }}" target="_blank" rel="noopener" class="font-mono font-bold link">{{ $latestVersion }}</a>
                </span>
            </x-alert>
            @unless($isAppVersion)
                <x-alert icon="o-information-circle" class="alert-info mb-4">
                    {{ __('You are not using a version tag. Consider using docker tag "1" instead of "latest" for reliable update detection.') }}
                </x-alert>
            @endunless
        @elseif($latestVersion && !$currentVersion)
            <x-alert icon="o-exclamation-triangle" class="alert-warning mb-4">
                {{ __('Could not determine current version.') }}
                {{ __('Latest available:') }}
                <a href="{{ $releaseUrl }}" target="_blank" rel="noopener" class="font-mono font-semibold link">{{ $latestVersion }}</a>
            </x-alert>
        @elseif($currentVersion && !$latestVersion)
            <x-alert icon="o-exclamation-triangle" class="alert-warning mb-4">
                {{ __('Could not determine latest version.') }}
                {{ __('Current version:') }}
                <span class="font-mono font-semibold">{{ $currentVersion }}</span>
            </x-alert>
        @else
            <x-alert icon="o-exclamation-triangle" class="alert-warning mb-4">
                {{ __('Could not determine version information.') }}
            </x-alert>
        @endif

        <x-tabs selected="docker-compose-tab" label-class="tabs-sm">

            {{-- Docker Compose tab --}}
            <x-tab name="docker-compose-tab" :label="__('Docker Compose')" icon="devicon.docker">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-sm opacity-70">
                        {{ __('Run from the folder where your docker-compose.yml is located') }}
                    </p>
                    <x-button
                        icon="o-clipboard-document"
                        class="btn-ghost btn-xs"
                        :label="__('Copy')"
                        x-clipboard="$wire.dockerComposeCommand"
                        x-on:clipboard-copied="$wire.success('{{ __('Copied to clipboard!') }}', null, 'toast-bottom')"
                    />
                </div>
                <pre class="bg-neutral text-neutral-content rounded-box p-5 text-sm overflow-x-auto"><code class="break-all select-all whitespace-pre-wrap">{{ $dockerComposeCommand }}</code></pre>
            </x-tab>

            {{-- Helm / Kubernetes tab --}}
            <x-tab name="helm-tab" :label="__('Helm / Kubernetes')" icon="devicon.kubernetes">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-sm opacity-70">
                        {{ __('Update the Helm repository and upgrade the release') }}
                    </p>
                    <x-button
                        icon="o-clipboard-document"
                        class="btn-ghost btn-xs"
                        :label="__('Copy')"
                        x-clipboard="$wire.helmCommand"
                        x-on:clipboard-copied="$wire.success('{{ __('Copied to clipboard!') }}', null, 'toast-bottom')"
                    />
                </div>
                <pre class="bg-neutral text-neutral-content rounded-box p-5 text-sm overflow-x-auto"><code class="break-all select-all whitespace-pre-wrap">{{ $helmCommand }}</code></pre>
            </x-tab>

            {{-- Docker tab --}}
            <x-tab name="docker-tab" :label="__('Docker')" icon="devicon.docker">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-sm opacity-70">
                        {{ __('Run from the folder where your .env file is located') }}
                    </p>
                    <x-button
                        icon="o-clipboard-document"
                        class="btn-ghost btn-xs"
                        :label="__('Copy')"
                        x-clipboard="$wire.dockerCommand"
                        x-on:clipboard-copied="$wire.success('{{ __('Copied to clipboard!') }}', null, 'toast-bottom')"
                    />
                </div>
                <pre class="bg-neutral text-neutral-content rounded-box p-5 text-sm overflow-x-auto"><code class="break-all select-all whitespace-pre-wrap">{{ $dockerCommand }}</code></pre>
            </x-tab>

        </x-tabs>

        <x-slot:actions>
            <x-button :label="__('Done')" class="btn-primary" @click="$wire.showModal = false" />
        </x-slot:actions>

    </x-modal>
</div>
