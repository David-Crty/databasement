# `<x-table>` in depth

Source: `vendor/robsontenorio/mary/src/View/Components/Table.php`.

## Headers

`:headers` is an array of arrays. Recognised keys, all read directly in `Table.php`:

| Key | Effect |
|---|---|
| `key` | the `data_get()` path into the row, and the scoped-slot name suffix. Required. |
| `label` | the `<th>` text |
| `class` | classes for both the `<th>` and every `<td>` in that column (`cellClasses()` seeds from it) |
| `hidden` | `true` skips the column entirely (`isHidden()`) |
| `sortable` | `false` opts the column out of sorting (`isSortable()`) |
| `sortBy` | sort by a different column name than `key` |
| `format` | a callable `fn ($row, $field)`, or `['currency', '2.,', '$']`, or `['date', 'd/m/Y']` |
| `disableLink` | `true` keeps this cell out of the row-wide `link` anchor (`hasLink()`) |

A `key` containing a dot addresses nested data (`'server.name'`) and its scoped slot is written with
the dot (`@scope('cell_server.name', $row)`); the directive rewrites it to `cell_server___name`
internally.

## Scoped slots

`@scope('<name>', $row)` ... `@endscope` compiles to a closure that the component invokes.

- `cell_<key>` replaces one cell's content.
- `header_<key>` replaces one header's label. It receives the header array, not a row.
- `actions` is a trailing right-aligned column; declare it as `@scope('actions', $row)`.
- `expansion` is the expanded-row body (needs `expandable`).
- `empty`, `tr`, `cell`, `footer` are plain slots.

`$loop` is available inside a scope (the directive injects the current loop object).

### The scope closure sees almost nothing from outside

`Blade::directive('scope')` builds `function ($row) use ($__env, $__bladeCompiler) { ... }`. Nothing
else is captured. Livewire's public properties are ordinary view variables, so they are **undefined**
inside the closure, including inside anything the closure `@include`s.

Two ways out:

```blade
{{-- 1. Reach the component through $this, which the closure inherits --}}
@scope('cell_comment', $snapshot)
    @if ($this->editCommentSnapshotId === $snapshot->id)
        ...
    @endif
@endscope

{{-- 2. Import extra variables by listing them after the closure argument --}}
@scope('cell_comment', $snapshot, $editingId)
    @if ($editingId === $snapshot->id) ... @endif
@endscope
```

Both are used here. Option 1 for component state (`snapshot/_comment.blade.php`, included from a
scope, reads `$this->editCommentSnapshotId`); option 2 for a value computed in the view or `render()`
(`@scope('cell_actions', $server, $canAdminer)` in `database-server/index.blade.php`,
`@scope('cell_members', $role, $memberCounts)` in `configuration/roles.blade.php`).

## Row and cell classes

`class=` on `<x-table>` lands on the `<table>` element, not on the outer wrapper. The wrapper's class
is `containerClass` (default `overflow-x-auto`).

`<tr>` classes come from `:row-decoration` only. It is a map of class name to a predicate:

```blade
:row-decoration="[
    'group' => fn () => true,
    'opacity-50' => fn (\App\Models\Snapshot $snapshot) => $snapshot->status === 'failed',
]"
```

`rowClasses()` runs every closure against the row and joins the truthy keys. A `fn () => true`
predicate therefore adds a class unconditionally, which is how this repo attaches `group` to each row
so cell content can use `group-hover:` affordances. Mary also always appends `hover:bg-base-200`
unless `no-hover` is set.

`:cell-decoration` is the same idea keyed by column:

```blade
:cell-decoration="['status' => ['text-error font-semibold' => fn ($row) => $row->failed]]"
```

`cellClasses()` starts from the header's own `class` and appends the truthy keys for that column.

## Sorting

Pass `:sort-by="$sortBy"` where `$sortBy` is `['column' => 'name', 'direction' => 'asc']` on the
Livewire component. Clicking a sortable `<th>` calls `$wire.set('sortBy', {column, direction})`,
toggling direction when the column is already active. Rename the target property with
`sort-by-property="mySortBy"`. Without `:sort-by`, `isSortable()` is false for every column and no
sort affordance renders.

## Pagination

`with-pagination` renders `<x-pagination :rows="$rows">` under the table; `$rows` must be a paginator.
`per-page="perPage"` additionally binds the per-page picker to that Livewire property with
`wire:model.live`; `:per-page-values` overrides `[10, 20, 50, 100]`.

## Row links

`link="/snapshots/{id}"` wraps every cell's content in an `<a wire:navigate>`, substituting `{token}`
placeholders with `data_get($row, $token)` (dotted paths work).

To build the link with `route()`, pass the placeholder as a bracketed parameter:
`:link="route('snapshots.show', ['snapshot' => '[id]'])"`. `route()` URL-encodes the brackets to
`%5B`/`%5D`, and `redirectLink()` turns those back into `{`/`}` before substituting tokens. Passing
`'{id}'` instead does not work, since `route()` encodes the braces to `%7B`/`%7D`, which
`redirectLink()` leaves alone.

Opt a column out with `'disableLink' => true` in its header, which is what you want for an actions
column.

## Selection and expansion

`selectable` adds a checkbox column bound with `wire:model` to an array of `selectableKey` values and
dispatches `row-selection` / `row-selection-all` browser events. `expandable` adds a chevron column
driven by the same `wire:model` array, keyed by `expandableKey`, and shows the `expansion` scoped slot
for rows where `data_get($row, $expandableCondition)` is truthy. Using both at once throws an
exception in the constructor.

## Empty state

`show-empty-text` renders `emptyText` (default `'No records found.'`) when `count($rows) === 0`. An
`<x-slot:empty>` renders in the same place and is the way to give the empty state real markup.
