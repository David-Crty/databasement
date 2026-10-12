<div>
    <x-configuration-header active="authentication" :subtitle="__('OAuth and Single Sign-On authentication settings (read-only).')" />

    <x-card shadow class="min-w-0">
        <x-card-heading :title="__('SSO')" :subtitle="__('OAuth and Single Sign-On authentication settings.')" docs="self-hosting/configuration/sso" />
        @include('livewire.configuration._config-table', ['rows' => $ssoConfig])
    </x-card>
</div>
