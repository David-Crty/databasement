# Patterns used in this repo

Worked examples of how the views in `resources/views/livewire/` put Mary together. Props and
defaults live in `components.md`; this file only shows the house style.

## Tables

`<x-table>` with `:headers` from a component method, `@scope` slots for cell rendering, and
`with-pagination` + `:sort-by` for the index screens. Full detail in `table.md`.

```blade
<x-card shadow>
    <x-table
        :headers="$headers"
        :rows="$snapshots"
        :sort-by="$sortBy"
        with-pagination
        :row-decoration="[
            'group' => fn () => true,
            'bg-error/5' => fn ($snapshot) => $snapshot->job?->status?->value === 'failed',
        ]"
    >
        @scope('cell_created_at', $snapshot)
            <div>{{ $snapshot->created_at->diffForHumans() }}</div>
            <div class="text-sm text-base-content/60">{{ \App\Support\Formatters::humanDate($snapshot->created_at) }}</div>
        @endscope

        @scope('actions', $snapshot)
            <x-button icon="o-trash" wire:click="confirmDeleteSnapshot('{{ $snapshot->id }}')" spinner
                      :tooltip="__('Delete')" class="btn-ghost btn-sm text-error" />
        @endscope

        <x-slot:empty>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-icon name="o-archive-box" class="w-10 h-10 text-base-content/30 mb-3" />
                <p class="font-medium">{{ __('No snapshots yet') }}</p>
            </div>
        </x-slot:empty>
    </x-table>
</x-card>
```

```php
// app/Livewire/Snapshot/Index.php
public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

public function headers(): array
{
    return [
        ['key' => 'subject', 'label' => __('Database'), 'sortable' => false],
        ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-48'],
        ['key' => 'status', 'label' => __('Status'), 'class' => 'w-44'],
    ];
}
```

`'group' => fn () => true` in `:row-decoration` is how rows get the `group` class, so cell content can
use `group-hover:` affordances.

`:cell-decoration`, `link=`, `expandable`, `selectable`, `striped` and `no-hover` are supported by the
component but unused in this repo.

## Modals

A boolean Livewire property bound with `wire:model` (see `components.md` for the drive modes).

```blade
<x-modal wire:model="showCreateModal" :title="__('Create API Token')" separator>
    <x-input wire:model="tokenName" :label="__('Token Name')" :hint="__('A descriptive name')" />

    <x-slot:actions>
        <x-button :label="__('Cancel')" wire:click="closeCreateModal" spinner />
        <x-button :label="__('Create')" wire:click="createToken" class="btn-primary" spinner />
    </x-slot:actions>
</x-modal>
```

Add `persistent` for a modal that must not be dismissed (the "copy this token now" dialogs use it),
`box-class="max-w-2xl"` or `box-class="w-11/12 max-w-6xl"` to widen it, and `@close="$wire.reset()"`
to react to closing. For destructive confirmations use `<x-delete-confirmation-modal>` rather than a
fresh `x-modal`.

## Selects

Options are always `[['id' => ..., 'name' => ...], ...]`, built in the component class:

```php
public function statusOptions(): array
{
    return [
        ['id' => 'completed', 'name' => __('Completed')],
        ['id' => 'failed', 'name' => __('Failed')],
    ];
}
```

```blade
<x-select
    :placeholder="__('All Types')"
    placeholder-value=""
    wire:model.live="dbTypeFilter"
    :options="$dbTypeOptions"
    class="!select-sm w-40" />
```

`placeholder-value=""` gives the placeholder option an empty value, which is what the filter
properties compare against. Use `option-value` / `option-label` when the array keys differ. For
multi-select use `<x-choices-offline ... searchable>` (`x-choices`, the server-side search variant,
is unused here).

## Forms

Forms bind to a per-feature Form object (`App\Livewire\Volume\Form`, `App\Livewire\DatabaseServer\Form`)
via `wire:model="form.field"`. Both `<x-form wire:submit="save">` and a plain
`<form wire:submit="save">` are used; the plain form is the majority. Validation errors render
themselves, so no `@error` blocks.

```blade
<x-form wire:submit="save" class="space-y-6">
    <x-input
        wire:model="form.name"
        :label="__('Agent Name')"
        :placeholder="__('e.g., Production Network Agent')"
        :hint="__('A friendly name to identify this agent')"
        required />

    <x-slot:actions>
        <x-button class="btn-ghost" :link="route('agents.index')" wire:navigate>{{ __('Cancel') }}</x-button>
        <x-button class="btn-primary" type="submit" icon="o-check" spinner="save">{{ $submitLabel }}</x-button>
    </x-slot:actions>
</x-form>
```

Field-adjacent actions go in `<x-slot:append>` as `join-item` buttons:

```blade
<x-select wire:model.live="form.backup_schedule_id" :label="__('Backup Schedule')" :options="$scheduleOptions">
    <x-slot:append>
        <x-button wire:click="refreshSchedules" icon="o-arrow-path" class="btn-ghost join-item"
                  :tooltip-bottom="__('Refresh schedule list')" spinner />
    </x-slot:append>
</x-select>
```

## Buttons

```blade
<x-button :label="__('Backup now')" icon="bi.database-fill-up" wire:click="runBackupAll" spinner
          class="btn-outline btn-info btn-sm" />
<x-button icon="o-trash" wire:click="confirmDelete" spinner :tooltip-left="__('Delete')" class="btn-ghost btn-sm text-error" />
<x-button :label="__('Edit')" icon="o-pencil" :link="route('database-servers.edit', $server)"
          class="btn-primary btn-sm" />
<x-button icon="o-arrow-down-tray" :link="route('snapshots.download', $snapshot)" external
          :tooltip="__('Download')" class="btn-ghost btn-sm text-primary" />
<x-button :label="__('Close')" @click="$wire.showDownloadModal = false" />
```

`label=` and the default slot are alternatives; use whichever reads better. `link=`, `external` and
the tooltip props are described under `x-button` in `components.md`.

## Icons

`<x-icon name="...">`, and the same names on any component's `icon=` prop. Heroicons (`o-server`)
are the overwhelming majority; Bootstrap Icons (`bi.database-fill-down`) and devicons
(`devicon.docker`) cover the rest. Name resolution and sizing are under `x-icon` in `components.md`.

- blade-icons' own tag form still works for one-offs: `<x-bi-github class="w-4 h-4" />`.
- `config/blade-devicons.php` adds `resource_path('svg/devicons')` as an override directory.

Icons are usually resolved from enums: `<x-icon :name="$snapshot->database_type->icon()" class="w-6 h-6" />`.

## Layout

`resources/views/layouts/app.blade.php` is the only place `x-main`, `x-nav` and `x-menu` appear.
`<x-main>` holds `<x-slot:sidebar drawer="main-drawer" collapsible>` and `<x-slot:content>`; the
sidebar contains `<x-menu activate-by-route>` with `x-menu-item`s that carry `link` +
`wire:navigate`, and a bottom `x-menu-sub` for the user block.

`<x-toast />` is mounted once in `layouts/app.blade.php` and once in `layouts/auth.blade.php`. Toasts
are covered in SKILL.md.
