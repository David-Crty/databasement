@props(['form', 'isEdit' => false, 'showPort' => true])

<!-- Client-server database connection fields -->
<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.host"
        :label="__('Host')"
        :placeholder="__('e.g., localhost or 192.168.1.100')"
        type="text"
        required
    />

    @if($showPort)
        <x-input
            wire:model="form.port"
            :label="__('Port')"
            :placeholder="__('e.g., 3306')"
            type="number"
            min="1"
            max="65535"
            required
        />
    @endif
</div>

<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.username"
        :label="__('Username')"
        :placeholder="$form->hasOptionalCredentials() ? __('Optional (for authenticated servers)') : __('Database username')"
        type="text"
        :required="!$form->hasOptionalCredentials()"
        autocomplete="off"
    />

    <x-password
        wire:model="form.password"
        :label="__('Password')"
        :placeholder="$isEdit ? __('Leave blank to keep current') : __('Database password')"
        :required="!$isEdit && !$form->hasOptionalCredentials()"
        autocomplete="off"
    />
</div>
