<x-layouts.app :title="__('Create Database Server')">
    <div class="mx-auto max-w-4xl">
        <flux:heading size="xl" class="mb-2">{{ __('Create Database Server') }}</flux:heading>
        <flux:subheading class="mb-6">{{ __('Add a new database server to manage backups') }}</flux:subheading>

        @if (session('status'))
            <x-banner variant="success" dismissible class="mb-6">
                {{ session('status') }}
            </x-banner>
        @endif

        <flux:card class="space-y-6">
            <form wire:submit="save" class="space-y-6">
                <!-- Basic Information -->
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Basic Information') }}</flux:heading>

                    <flux:input
                        wire:model="name"
                        :label="__('Server Name')"
                        :placeholder="__('e.g., Production MySQL Server')"
                        type="text"
                        required
                        autofocus
                    />
                    @error('name') <flux:error>{{ $message }}</flux:error> @enderror

                    <flux:textarea
                        wire:model="description"
                        :label="__('Description')"
                        :placeholder="__('Optional description for this server')"
                        rows="3"
                    />
                    @error('description') <flux:error>{{ $message }}</flux:error> @enderror
                </div>

                <!-- Connection Details -->
                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Connection Details') }}</flux:heading>

                    <flux:select
                        wire:model="database_type"
                        :label="__('Database Type')"
                        required
                    >
                        <option value="mysql">MySQL</option>
                        <option value="mariadb">MariaDB</option>
                        <option value="postgresql">PostgreSQL</option>
                        <option value="sqlite">SQLite</option>
                    </flux:select>
                    @error('database_type') <flux:error>{{ $message }}</flux:error> @enderror

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <flux:input
                                wire:model="host"
                                :label="__('Host')"
                                :placeholder="__('e.g., localhost or 192.168.1.100')"
                                type="text"
                                required
                            />
                            @error('host') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>

                        <div>
                            <flux:input
                                wire:model="port"
                                :label="__('Port')"
                                :placeholder="__('e.g., 3306')"
                                type="number"
                                required
                            />
                            @error('port') <flux:error>{{ $message }}</flux:error> @enderror
                        </div>
                    </div>

                    <flux:input
                        wire:model="database_name"
                        :label="__('Database Name')"
                        :placeholder="__('Optional: specific database name')"
                        type="text"
                    />
                    @error('database_name') <flux:error>{{ $message }}</flux:error> @enderror
                </div>

                <!-- Authentication -->
                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Authentication') }}</flux:heading>

                    <flux:input
                        wire:model="username"
                        :label="__('Username')"
                        :placeholder="__('Database username')"
                        type="text"
                        required
                        autocomplete="off"
                    />
                    @error('username') <flux:error>{{ $message }}</flux:error> @enderror

                    <flux:input
                        wire:model="password"
                        :label="__('Password')"
                        :placeholder="__('Database password')"
                        type="password"
                        required
                        autocomplete="off"
                    />
                    @error('password') <flux:error>{{ $message }}</flux:error> @enderror
                </div>

                <!-- Submit Button -->
                <div class="flex items-center justify-end gap-3 pt-4">
                    <flux:button variant="ghost" href="{{ route('dashboard') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" type="submit">
                        {{ __('Create Database Server') }}
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </div>
</x-layouts.app>
