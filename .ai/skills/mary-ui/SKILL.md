---
name: mary-ui
description: >-
  Mary UI (robsontenorio/mary 2.x, daisyUI 5) reference for this repo's Blade/Livewire views. Use
  whenever writing or editing markup under resources/views that uses `<x-…>` components (forms,
  x-table and @scope cells, modals, buttons and `spinner`, badges, alerts, cards, selects, tabs,
  menus, popovers, icons), styling one with daisyUI/Tailwind classes, or debugging a class, attribute
  or prop that has no effect. Lists the available components and how to read their source for props,
  plus this project's rules, toasts and local components. Not for mail templates (`<x-mail::*>`) or the Docusaurus docs
  site.
---

# Mary UI in Databasement

Mary UI is a Blade component library that renders [daisyUI](https://daisyui.com) markup. It adds no
CSS of its own: every component's `render()` returns a Blade heredoc built out of daisyUI class names
(`btn`, `alert`, `card`, `input`, `modal`) plus a little Alpine. Installed here as
`robsontenorio/mary` ^2.4 (currently 2.9.9) on daisyUI 5 and Tailwind 4.

The practical consequence: **styling is done with classes, not with variant props.** There is no
`variant`, `color` or `size` prop on `x-button` or `x-alert`. You pass daisyUI classes:

```blade
<x-button :label="__('Delete')" class="btn-error btn-sm" wire:click="delete" spinner />
<x-alert :title="$errorMessage" class="alert-error mb-4" icon="o-x-circle" />
<x-badge value="SSH" class="badge-warning badge-xs" />
<x-loading class="loading-spinner loading-sm" />
```

Alerts take `class="alert-success"` / `alert-error` / `alert-warning` / `alert-info`. Any daisyUI
class or Tailwind utility can be added this way, but *where* it lands differs per component (see
"Check where `$attributes` land" below).

## Using a component

This skill does not document props: the installed source does, exactly. For any `<x-name>`, read
`vendor/robsontenorio/mary/src/View/Components/<Name>.php` (kebab-case to PascalCase:
`x-menu-item` is `MenuItem.php`, `x-choices-offline` is `ChoicesOffline.php`).

- The **constructor** is the complete prop list with defaults. A prop that is not a constructor
  parameter does not exist; it falls through to `$attributes`.
- The **`render()` heredoc** is the exact markup: which daisyUI classes it applies, which slots it
  reads, and where `{{ $attributes }}` lands.
- `https://mary-ui.com/docs/components/<name>` tracks the latest release, not the installed one
  (2.9.9 at the time of writing, see `composer.lock`). When it disagrees with the class, the class
  wins.

For house style, copy an existing screen rather than composing from scratch:
`livewire/volume/index.blade.php` and `livewire/snapshot/index.blade.php` (index tables, filters,
`@scope` cells, pagination), `livewire/volume/_form.blade.php` (form fields),
`livewire/configuration/backup.blade.php` (modals with forms), `layouts/app.blade.php` (the only
use of `x-main`, `x-nav` and `x-menu`).

### Available components

- **Form:** `input`, `password`, `textarea`, `select`, `select-group`, `choices`, `choices-offline`,
  `checkbox`, `toggle`, `radio`, `group`, `range`, `file`, `image-library`, `datetime`, `datepicker`,
  `colorpicker`, `tags`, `pin`, `signature`, `editor`, `markdown`, `form`, `errors`
- **Actions:** `button`, `dropdown`, `swap`, `theme-toggle`
- **Layout and navigation:** `main`, `nav`, `menu`, `menu-item`, `menu-sub`, `menu-separator`,
  `menu-title`, `header`, `card`, `tabs`, `tab`, `steps`, `step`, `collapse`, `accordion`, `drawer`,
  `breadcrumbs`, `hr`
- **Data display:** `table`, `pagination`, `badge`, `stat`, `avatar`, `list-item`, `timeline-item`,
  `icon`, `kbd`, `code`, `diff`, `chart`, `calendar`, `image-gallery`, `carousel`, `rating`
- **Feedback and overlays:** `modal`, `alert`, `toast`, `popover`, `loading`, `progress`,
  `progress-radial`, `spotlight`

`chart`, `editor`, `markdown`, `code`, `calendar`, `signature`, `diff` and `image-gallery` need a
JS library this app does not load, and `spotlight` needs an `App\Support\Spotlight` class that does
not exist. Wire those up before using them. `radio` is unused here in favour of the local
`x-radio-card`.

## Rules and gotchas

### Translated attributes use `:attr` bindings

```blade
<x-input label="{{ __('Host') }}" />   {{-- wrong: double-encodes ' " & --}}
<x-input :label="__('Host')" />        {{-- right --}}
```

`{{ }}` runs `htmlspecialchars()` on the translation and the component escapes the value again when
it renders the prop, so `l'application` shows up as `l&#039;application`. The `:attr` form passes
the raw PHP value and the component escapes it once. This applies to every translated prop on every
component: `label`, `title`, `subtitle`, `hint`, `placeholder`, `tooltip*`, `value`.

### Loading states: `spinner`

Bare `spinner` targets the button's own `wire:click` expression, parameters included, so
`wire:click="confirmDelete('{{ $row->id }}')" spinner` spins only the clicked row.
`spinner="name"` targets that name. Either form adds `wire:target` plus
`wire:loading.attr="disabled"` and swaps the icon for a spinner while loading.

- every `<x-button>` / `<x-menu-item>` with `wire:click` takes bare `spinner`;
- a `type="submit"` button of a `wire:submit="save"` form takes `spinner="save"` (there is no
  `wire:click` to resolve, and an empty `wire:target` never matches);
- a `wire:click="$set('form.x', …)"` / `$toggle('form.x')` button takes `spinner="form.x"`, because
  those magic actions send a property update, not a method call;
- a classic POST form (auth pages, logout) uses `<x-submit-button>` instead of `x-button`;
- `@click="$wire.showModal = false"`, Alpine-only toggles and `link=` buttons need nothing.

### Selects and modals

`x-select` takes `:options` as `[['id' => ..., 'name' => ...], ...]`, built in the component class
(`option-value` / `option-label` rename the keys). `x-modal` is opened and closed through a boolean
Livewire property, `<x-modal wire:model="showCreateModal">`; Mary entangles it `.live`, so Escape
and backdrop clicks set it false. Destructive confirmations use `<x-delete-confirmation-modal>`.

### Form components render their own errors

`x-input`, `x-select`, `x-textarea`, `x-password`, `x-checkbox`, `x-toggle` and the rest derive the
error field from their `wire:model` value, render every message themselves, and add `!input-error` /
`!select-error` / `!textarea-error` to the control. Do not write an `@error` block next to one, and
do not add `x-errors` for the same field.

### `@scope` slots do not see the component's public properties

`Blade::directive('scope')` compiles to `function ($row) use ($__env, $__bladeCompiler)`, so
Livewire's public properties are undefined inside it, including in anything it `@include`s. Use
`$this->property` (the closure inherits `$this`), or list extra variables after the row:
`@scope('cell_actions', $server, $canAdminer)`.

### Never put a Blade directive inside a component tag

`@if` / `@else` inside the attribute list of an `<x-…>` tag is not parsed. Blade's
`ComponentTagCompiler` matches attributes with a regex before directives are compiled, so the
directive and everything after it leaks into the page as plain text and no component renders. It
works on a plain HTML element, which is why the mistake is easy to make.

```blade
{{-- Broken: emits `Locked')" icon="s-lock-closed" …` as text --}}
<x-button :label="__('Locked')" @if($canLock) wire:click="unlock" @else disabled @endif />

{{-- Right: bind the prop --}}
<x-button :label="__('Locked')" wire:click="unlock" :disabled="! $canLock" spinner />
```

Symptom: a control silently disappears and raw attribute text shows up nearby. An
`->assertDontSee('icon="s-lock-closed"', false)` in a Livewire test catches the regression.

### Check where `$attributes` land

A `class` or Alpine binding goes wherever the component's `render()` prints `{{ $attributes }}`,
which is often an inner element (inputs forward to the `<input>`, not the wrapper) and sometimes
nowhere: `x-popover`, `x-main`, `x-toast` and a few others drop them silently. Read the heredoc
before assuming a class will apply; a size modifier may need `!` (`class="!select-sm"`) to beat
Mary's base class.

### Other sharp edges

- **`x-dropdown` clips inside tables.** It is a `<details class="dropdown">` inside the table's
  `overflow-x-auto` container. Use the local `<x-floating-dropdown>`, which teleports to `<body>`.
- **`x-card`'s header keeps title and actions on one row.** `<x-card-heading>` is the responsive
  stacked version.
- **Tailwind scans the vendor source.** `resources/css/app.css` has
  `@source '../../vendor/robsontenorio/mary/src/View/Components/**/*.php';`, which is what makes
  Mary's hardcoded classes survive the build. Do not remove it.

## Toasts

Livewire components `use App\Traits\Toast;` and call `$this->success(...)`, `->warning(...)`,
`->error(...)`, `->info(...)` or `->toast($type, ...)`. **Never `use Mary\Traits\Toast`.** Mary's
helpers are `public`, which makes them client-callable Livewire actions on every component that uses
the trait, and Mary concatenates `$icon` into a `Blade::render()` string. The app's copy
(`app/Traits/Toast.php`, from the `fix(security)` change in #605) makes all five methods `protected`,
passes the icon as a bound `:name`, and falls back to `o-information-circle` when the name does not
match `/\A[A-Za-z0-9._-]+\z/`. `tests/Feature/Security/ToastMethodExposureTest.php` locks this.

Differences from Mary's trait:

| | `App\Traits\Toast` | `Mary\Traits\Toast` |
|---|---|---|
| visibility | `protected` | `public` |
| `position` default (success/warning/error/info) | `'toast-bottom'` | `null` |
| `timeout` default | `6000` success/info, `9000` warning/error | `3000` |
| return type | `?Redirector` | none |
| `flashAs` | flashes that session key as `true` | absent |

`redirectTo:` redirects with `navigate: true`; the toast stays on screen because `<x-toast>` wraps
itself in `@persist('mary-toaster')`. `flashAs:` exists for `App\Traits\BlocksDemoWrites`, which
passes `'demo_notice'` so tests can `assertSessionHas('demo_notice')`. 

`<x-toast>` renders `title` and `description` with `x-html`, so wrap untrusted values in `e()`.

Because the methods are protected, Alpine cannot call `$wire.success(...)`. Client-side toasts (a
clipboard copy, say) use the global `successToast(title, timeout = 6000)` from `resources/js/app.js`:

```blade
x-on:clipboard-copied="successToast('{{ __('Copied to clipboard!') }}')"
```

`<x-toast />` is mounted once in `layouts/app.blade.php` and once in `layouts/auth.blade.php`. Do not
add another.

## Registration and naming

This project has **not** published `config/mary.php`, so the prefix is empty: components are
`<x-button>`, `<x-table>`, `<x-modal>`, never `<x-mary-button>` (the `mary-` aliases exist for Mary's
internal use; do not write them in views). The provider renames blade-icons' own `<x-icon>` to
`<x-svg>` so Mary can own `x-icon`.

Blade resolves registered aliases *before* anonymous components in `resources/views/components/`, so
a Mary tag name always wins: never name a local component after a Mary one. If
`resources/views/components/<name>.blade.php` exists the tag is ours, otherwise it is Mary's.

### This project's own components

Check this list for something reusable before writing new markup.

| Tag | What it does |
|---|---|
| `x-ability-badges` | ability slugs as `badge-sm badge-ghost` chips, collapsed behind a `+N more` toggle |
| `x-ability-toggles` | grouped `x-checkbox` grid bound to one array target; shared by the Roles and User screens |
| `x-agent-status-indicator` | maps `online`/`offline`/`never` to a badge with icon and label |
| `x-app-brand` | home link with the wordmark, with collapsed/expanded sidebar variants |
| `x-card-heading` | card header that stacks title, subtitle and actions on small screens |
| `x-config-row` | two-column settings row: label plus description on the left, the control in the slot |
| `x-copy-input` | read-only input (or textarea when `multiline`) with a copy button and a 2s "copied" state |
| `x-databaserver-backup-index` | one-line summary chip for a backup schedule plus a "Backup now" action |
| `x-delete-confirmation-modal` | the shared `wire:model="showDeleteModal"` confirm dialog |
| `x-floating-dropdown` | Alpine dropdown teleported to `<body>`, so menus escape table `overflow` clipping |
| `x-id-popover` | last 7 chars of a ULID as a `<kbd>`, full id on hover via the native Popover API |
| `x-input-otp` | N single-character OTP inputs writing into one hidden field |
| `x-invitation-link-modal` | persistent modal showing an invitation URL with a clipboard button |
| `x-job-status-indicator` | job status to a badge; the `running` case swaps the icon for a spinner |
| `x-lazy-placeholder` | skeleton card in `stats`/`chart`/`list` shapes, used by the dashboard `placeholder()` methods |
| `x-logo-icon` | inline SVG logo with per-instance gradient ids |
| `x-radio-card` / `x-radio-card-group` | card-style radio group (used instead of Mary's `x-radio`, which is unused here) |
| `x-snapshot-volume-badge` | per-volume upload state for a snapshot file |
| `x-submit-button` | submit button for a classic POST form: Alpine flips it to disabled + spinner on `submit` |
| `x-volume-type-icon` | resolves a `VolumeType` to its icon and renders `x-icon` |
