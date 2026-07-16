<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('OAuth and Single Sign-On authentication settings (read-only).') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'authentication'])

    <x-card shadow class="min-w-0">
        <x-card-heading :title="__('SSO')" :subtitle="__('OAuth and Single Sign-On authentication settings.')">
            <x-button
                :label="__('Documentation')"
                icon="o-book-open"
                link="https://david-crty.github.io/databasement/self-hosting/configuration/sso"
                external
                class="btn-ghost btn-sm"
            />
        </x-card-heading>
        @include('livewire.configuration._config-table', ['rows' => $ssoConfig])
    </x-card>
</div>
