<div>
    <x-header :title="__('Changelog')" :subtitle="__('What changed in each release of Databasement')" separator>
        <x-slot:actions>
            @if ($this->currentVersion)
                <x-badge :value="'v'.$this->currentVersion" class="badge-primary badge-soft font-mono" />
            @endif
            <x-button
                :label="__('GitHub releases')"
                icon="bi.github"
                link="{{ config('app.github_repo') }}/releases"
                external
                class="btn-ghost btn-sm"
            />
        </x-slot:actions>
    </x-header>

    <x-card shadow>
        @if ($this->html)
            <div class="changelog">{!! $this->html !!}</div>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-icon name="o-document-text" class="w-10 h-10 text-base-content/30 mb-3" />
                <p class="font-medium">{{ __('No changelog is available for this build') }}</p>
            </div>
        @endif
    </x-card>
</div>
