<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <x-header title="{{ __('Delete account') }}" subtitle="{{ __('Delete your account and all of its resources') }}" size="text-lg" />
    </div>

    <x-button
        label="{{ __('Delete account') }}"
        class="btn-error"
        @click="$wire.showDeleteModal = true"
        data-test="delete-user-button"
    />

    <x-modal wire:model="showDeleteModal" title="{{ __('Are you sure you want to delete your account?') }}" class="backdrop-blur">
        <form wire:submit="deleteUser" class="space-y-6">
            <p>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted.') }}
                @unless($isOAuthUser)
                    {{ __('Please enter your password to confirm you would like to permanently delete your account.') }}
                @endunless
            </p>

            <x-alert icon="o-information-circle" class="alert-info">
                {!! __('Database servers, backups, snapshots, and other resources you created <strong>WILL NOT</strong> be deleted and will remain accessible to other users.') !!}
            </x-alert>

            @unless($isOAuthUser)
                <x-password wire:model="password" label="{{ __('Password') }}" />
            @endunless

            <div class="flex justify-end gap-2">
                <x-button label="{{ __('Cancel') }}" @click="$wire.showDeleteModal = false" />
                <x-button label="{{ __('Delete account') }}" class="btn-error" type="submit" data-test="confirm-delete-user-button" spinner="deleteUser" />
            </div>
        </form>
    </x-modal>
</section>
