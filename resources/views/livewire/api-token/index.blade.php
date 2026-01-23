<div>
    <div class="mx-auto max-w-4xl">
        <x-header title="{{ __('API Tokens') }}" subtitle="{{ __('Manage your personal access tokens for API authentication') }}" size="text-2xl" separator class="mb-6">
            <x-slot:actions>
                <x-button label="{{ __('Create Token') }}" icon="o-plus" class="btn-primary" wire:click="openCreateModal" />
            </x-slot:actions>
        </x-header>

        <x-card>
            @if($tokens->isEmpty())
                <div class="text-center text-base-content/50 py-8">
                    {{ __('No API tokens yet. Create one to get started.') }}
                </div>
            @else
                <div class="space-y-4">
                    @foreach($tokens as $token)
                        <div class="flex items-center justify-between p-4 bg-base-200 rounded-lg">
                            <div class="flex-1">
                                <div class="font-medium">{{ $token->name }}</div>
                                <div class="text-sm text-base-content/70">
                                    @if($token->tokenable)
                                        <span class="font-medium">{{ $token->tokenable->name }}</span>
                                        <span class="badge badge-sm badge-ghost ml-1">{{ ucfirst($token->tokenable->role) }}</span>
                                        &mdash;
                                    @endif
                                    {{ __('Created') }} {{ \App\Support\Formatters::humanDate($token->created_at) }}
                                    @if($token->last_used_at)
                                        &mdash; {{ __('Last used') }} {{ \App\Support\Formatters::humanDate($token->last_used_at) }}
                                    @else
                                        &mdash; {{ __('Never used') }}
                                    @endif
                                </div>
                            </div>
                            @if($this->canDelete($token))
                                <x-button
                                    icon="o-trash"
                                    wire:click="confirmDelete('{{ $token->id }}')"
                                    tooltip="{{ __('Revoke') }}"
                                    class="btn-ghost btn-sm text-error"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        {{-- Create Token Modal --}}
        <x-modal wire:model="showCreateModal" title="{{ __('Create API Token') }}" separator>
            <x-input
                wire:model="tokenName"
                label="{{ __('Token Name') }}"
                placeholder="{{ __('e.g., CI/CD Pipeline, Backup Script') }}"
                hint="{{ __('A descriptive name to identify this token') }}"
            />

            <x-slot:actions>
                <x-button label="{{ __('Cancel') }}" wire:click="closeCreateModal" />
                <x-button label="{{ __('Create') }}" wire:click="createToken" class="btn-primary" spinner="createToken" />
            </x-slot:actions>
        </x-modal>

        {{-- Show New Token Modal --}}
        <x-modal wire:model="showTokenModal" title="{{ __('API Token Created') }}" separator persistent>
            <x-alert class="alert-warning mb-4" icon="o-exclamation-triangle">
                {{ __('Make sure to copy your token now. You won\'t be able to see it again!') }}
            </x-alert>

            <div class="flex gap-2">
                <div class="flex-1">
                    <x-input
                        wire:model="newToken"
                        readonly
                        class="w-full font-mono text-sm"
                    />
                </div>
                <x-button
                    icon="o-clipboard-document"
                    class="btn-primary"
                    x-clipboard="$wire.newToken"
                    x-on:clipboard-copied="$wire.success('{{ __('Token copied to clipboard!') }}', null, 'toast-bottom')"
                    tooltip="{{ __('Copy') }}"
                />
            </div>

            <x-slot:actions>
                <x-button label="{{ __('Done') }}" class="btn-primary" wire:click="closeTokenModal" />
            </x-slot:actions>
        </x-modal>

        {{-- Delete Confirmation Modal --}}
        <x-modal wire:model="showDeleteModal" title="{{ __('Revoke API Token') }}" separator>
            <p>{{ __('Are you sure you want to revoke this token? Any applications using this token will no longer be able to access the API.') }}</p>

            <x-slot:actions>
                <x-button label="{{ __('Cancel') }}" wire:click="closeDeleteModal" />
                <x-button label="{{ __('Revoke Token') }}" class="btn-error" wire:click="deleteToken" spinner="deleteToken" />
            </x-slot:actions>
        </x-modal>
    </div>
</div>
