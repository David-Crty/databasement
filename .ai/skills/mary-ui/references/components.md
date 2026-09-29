# Mary UI component catalogue (v2.9.9)

Every component registered by `MaryServiceProvider::registerComponents()`. Props below are the
constructor parameters, verbatim from `vendor/robsontenorio/mary/src/View/Components/<Name>.php`.
Anything not listed is not a prop: it falls through to `$attributes` and lands on whatever element
the component merges attributes onto (see `attributes.md` for where that is per component).

Blade kebab-cases camelCase props: `$noWireNavigate` is written `no-wire-navigate`, `$withPagination`
is `with-pagination`, `$rowDecoration` is `:row-decoration`.

Every component also takes `$id`, used only to suffix the internal `wire:key`/uuid, except
`x-form`, `x-header`, `x-main`, `x-nav` and `x-toast`, which have no `$id` prop.

## Contents

- [Form inputs](#form-inputs): `x-input`, `x-password`, `x-textarea`, `x-select`, `x-select-group`,
  `x-choices`, `x-choices-offline`, `x-checkbox`, `x-toggle`, `x-radio`, `x-group`, `x-range`,
  `x-file`, `x-image-library`, `x-datetime`, `x-datepicker`, `x-colorpicker`, `x-tags`, `x-pin`,
  `x-signature`, `x-editor`, `x-markdown`, `x-code`, `x-form`, `x-errors`
- [Buttons and actions](#buttons-and-actions): `x-button`, `x-dropdown`, `x-swap`, `x-theme-toggle`
- [Layout and navigation](#layout-and-navigation): `x-main`, `x-nav`, `x-menu` and children,
  `x-header`, `x-card`, `x-tabs`, `x-tab`, `x-steps`, `x-step`, `x-collapse`, `x-accordion`,
  `x-drawer`, `x-breadcrumbs`, `x-hr`
- [Data display](#data-display): `x-table`, `x-pagination`, `x-badge`, `x-stat`, `x-avatar`,
  `x-list-item`, `x-timeline-item`, `x-icon`, `x-kbd`, `x-diff`, `x-chart`, `x-calendar`,
  `x-image-gallery`, `x-carousel`, `x-rating`
- [Feedback and overlays](#feedback-and-overlays): `x-modal`, `x-alert`, `x-toast`, `x-popover`,
  `x-loading`, `x-progress`, `x-progress-radial`, `x-spotlight`

---

## Form inputs

### `<x-input>`
Text input inside a daisyUI `fieldset`. Renders its own label, hint and validation errors.

| Prop | Type | Default |
|---|---|---|
| `label` | `?string` | `null` |
| `icon` / `iconRight` | `?string` | `null` |
| `hint` | `?string` | `null` |
| `hintClass` | `?string` | `'fieldset-label'` |
| `prefix` / `suffix` | `?string` | `null` |
| `inline` | `?bool` | `false` (floating label) |
| `clearable` | `?bool` | `false` |
| `money` | `?bool` | `false` |
| `locale` | `?string` | `'en-US'` |
| `popover`, `popoverIcon`, `popoverTriggerClass`, `popoverContentClass` | `?string` | `null`, `'o-question-mark-circle'`, `''`, `''` |
| `errorField` | `?string` | `null` (falls back to the `wire:model` name) |
| `errorClass` | `?string` | `'text-error'` |
| `omitError` | `?bool` | `false` |
| `firstErrorOnly` | `?bool` | `false` |

Slots: `prepend`, `append` (join-group elements around the field).

```blade
<x-input :label="__('Host')" wire:model="form.host" :placeholder="__('db.example.com')" />
```

### `<x-password>`
Same shape as `x-input` plus a visibility toggle.

Extra props: `passwordIcon` (`'o-eye-slash'`), `passwordVisibleIcon` (`'o-eye'`),
`passwordIconTabindex` (`false`), `right` (`false`, toggle on the right), `onlyPassword` (`false`,
renders a bare input with no toggle). Note `popover` here is only `popover` + `popoverIcon`, no
trigger/content class props.

```blade
<x-password :label="__('Password')" wire:model="form.password" right />
```

### `<x-textarea>`
Props: `label`, `hint`, `hintClass`, `inline`, the four `popover*` props, and the four validation
props. Rows/`maxlength` etc. pass through `$attributes` onto the `<textarea>`.

```blade
<x-textarea :label="__('Comment')" wire:model="comment" rows="4" />
```

### `<x-select>`
Native `<select>`. Options are an array or Collection of arrays/objects.

| Prop | Type | Default |
|---|---|---|
| `label`, `icon`, `iconRight`, `hint`, `hintClass`, `prefix`, `suffix` | `?string` | `null` (`hintClass` `'fieldset-label'`) |
| `placeholder` | `?string` | `null` (renders a leading `<option>`) |
| `placeholderValue` | `?string` | `null` (that option's value) |
| `inline` | `?bool` | `false` |
| `optionValue` | `?string` | `'id'` |
| `optionLabel` | `?string` | `'name'` |
| `options` | `Collection\|array` | empty |
| popover + validation props | | as `x-input` |

Slots: `prepend`, `append`. An option row with `'disabled' => true` renders disabled.

```blade
<x-select
    :label="__('Type')"
    wire:model="form.type"
    :options="[['id' => 'mysql', 'name' => 'MySQL'], ['id' => 'postgresql', 'name' => 'PostgreSQL']]"
    :placeholder="__('Select a type')" />
```

### `<x-select-group>`
Same as `x-select` but `options` is a nested structure rendering `<optgroup>`s.

### `<x-choices>` / `<x-choices-offline>`
Searchable single/multi-select with a dropdown. `x-choices` calls a Livewire method for the search
(`searchFunction`, default `'search'`); `x-choices-offline` filters a fixed list client-side.

Notable props: `searchable`, `single`, `compact`, `compactText` (`'selected'`), `allowAll`,
`allowAllText` (`'Select all'`), `removeAllText` (`'Remove all'`), `debounce` (`'250ms'`),
`minChars` (`0`), `optionValue` (`'id'`), `optionLabel` (`'name'`), `optionSubLabel` (`''`),
`optionAvatar` (`'avatar'`), `valuesAsString`, `escapeValues`, `height` (`'max-h-64'`),
`noResultText` (`'No results found.'`), `clearable`, `inline`, `icon`, `iconRight`, `prefix`,
`suffix`, plus popover and validation props. `x-choices` additionally has `noProgress` and
`searchFunction`; `x-choices-offline` has neither.

Slots: `item`, `selection`, `prepend`, `append`.

### `<x-checkbox>` / `<x-toggle>`
Props: `label`, `right` (label/control order), `hint`, `hintClass`, and the four validation props.
`x-toggle` has no `right`-independent extras. Both put `class=` on the inner `<input>`, so
`class="checkbox-primary"` / `class="toggle-primary"` works.

```blade
<x-toggle :label="__('Enabled')" wire:model="form.enabled" class="toggle-primary" />
```

### `<x-radio>`
Radio list from `options`. Props: `label`, `hint`, `hintClass`, `optionValue` (`'id'`),
`optionLabel` (`'name'`), `optionHint` (`'hint'`), `options`, `inline`, popover + validation props.

### `<x-group>`
A joined radio-button bar (segmented control). Props: `label`, `hint`, `hintClass`, `optionValue`,
`optionLabel`, `options`, validation props. Renders `join-item btn` radios.

### `<x-range>`
daisyUI range slider. Props: `label`, `hint`, `hintClass`, `min` (`0`), `max` (`100`), popover +
validation props.

### `<x-file>`
File upload with optional cropper. Props: `label`, `hint`, `hintClass`, `hideProgress`,
`cropAfterChange`, `changeText` (`'Change'`), `cropTitleText`, `cropCancelText`, `cropSaveText`,
`cropConfig` (array), `cropMimeType` (`'image/png'`), popover + validation props. Slot: default slot
is the preview.

### `<x-image-library>`
Multi-image upload with per-image crop/remove. Props include `label`, `hint`, `hideErrors`,
`hideProgress`, `changeText`, `cropText`, `removeText`, `crop*Text`, `addFilesText`, `cropConfig`,
`preview` (`Collection`), popover props. Pairs with the `Mary\Traits\WithMediaSync` trait.

### `<x-datetime>` / `<x-datepicker>`
`x-datetime` is a native `<input type="date|datetime-local">`; `x-datepicker` is the Flatpickr-backed
widget configured through `config` (array). Both take `label`, `icon`, `iconRight`, `hint`,
`hintClass`, `inline`, popover props, `prepend`/`append` slots and validation props. `x-datepicker`
also has `clearable` and `config`.

### `<x-colorpicker>`
Props: `label`, `icon` (`''`), `iconRight`, `hint`, `hintClass`, `prefix`, `suffix`, `inline`,
`clearable`, popover + validation props.

### `<x-tags>`
Free-text tag input bound to an array. Props: `label`, `hint`, `hintClass`, `icon`, `iconRight`,
`inline`, `clearable`, `prefix`, `suffix`, popover props, `prepend`/`append` slots, validation props.

### `<x-pin>`
OTP-style pin entry. Props: `size` (`int`, required), `numeric`, `hide`, `hideType` (`'disc'`),
`noGap`, validation props (`errorClass` defaults to `'text-error text-xs pt-2'`).

### `<x-signature>`
Canvas signature pad. Props: `height` (`'250'`), `clearText` (`'Clear'`), `hint`,
`hintClass` (`'fieldset-label text-xs pt-1'`), `config` (array), `clearBtnStyle`, validation props.

### `<x-editor>` / `<x-markdown>` / `<x-code>`
Rich editors. `x-editor` is TinyMCE (`disk` `'public'`, `folder` `'editor'`, `gplLicense`, `config`);
`x-markdown` is EasyMDE (`disk`, `folder` `'markdown'`, `config`); `x-code` is Ace
(`language` `'javascript'`, `lightTheme` `'github_light_default'`, `darkTheme` `'github_dark'`,
`lightClass` `'light'`, `darkClass` `'dark'`, `height` `'200px'`, `lineHeight` `'2'`, `printMargin`
`false`). All three take `label`/`hint`; the first two take popover + validation props. Each needs its
JS asset loaded; none is used in this repo.

### `<x-form>`
A `<form>` wrapper laying children out in a `grid grid-flow-row auto-rows-min gap-3`.

Props: `noSeparator` (`false`). Slot: `actions` (rendered after an `<hr>` in a right-aligned flex row).

```blade
<x-form wire:submit="save">
    <x-input :label="__('Name')" wire:model="form.name" />

    <x-slot:actions>
        <x-button :label="__('Cancel')" link="{{ route('volumes.index') }}" />
        <x-button :label="__('Save')" class="btn-primary" type="submit" spinner="save" />
    </x-slot:actions>
</x-form>
```

### `<x-errors>`
Validation-summary alert. Props: `title`, `description`, `icon` (`'o-x-circle'`), `only` (array of
field names to restrict to).

---

## Buttons and actions

### `<x-button>`
Renders a `<button>`, or an `<a>` when `link` is set.

| Prop | Type | Default | Notes |
|---|---|---|---|
| `label` | `?string` | `null` | when absent, the default slot is the content |
| `icon` / `iconRight` | `?string` | `null` | Mary icon names |
| `spinner` | `?string` | `null` | see below |
| `link` | `?string` | `null` | switches the tag to `<a href>` |
| `external` | `?bool` | `false` | adds `target="_blank"`, skips `wire:navigate` |
| `noWireNavigate` | `?bool` | `false` | keeps the `<a>` but drops `wire:navigate` |
| `responsive` | `?bool` | `false` | hides the label below `lg` |
| `badge` / `badgeClasses` | `?string` | `null` | small badge after the label |
| `tooltip`, `tooltipLeft`, `tooltipRight`, `tooltipBottom` | `?string` | `null` | sets `data-tip` and the daisyUI tooltip position |

`spinner` resolution (`Button::spinnerTarget()`): a bare `spinner` attribute (Blade passes `true`,
and `true == 1`) targets the value of the button's own `wire:click`; `spinner="methodName"` targets
that method by name. Either way the button gets `wire:target=... wire:loading.attr="disabled"`, the
icon is hidden while loading and a `loading loading-spinner` span is shown.

An `<a>` produced by `link` carries `wire:navigate` unless `external` or `no-wire-navigate`.

```blade
<x-button
    :label="__('Run Backup')"
    icon="o-play"
    class="btn-primary btn-sm"
    wire:click="runBackup"
    spinner />
```

### `<x-dropdown>`
A `<details class="dropdown">` anchored with Alpine. Props: `label`, `icon` (`'o-chevron-down'`),
`right`, `top`, `noXAnchor`, `scroll`, `maxHeight` (`'max-h-96'`). Slot: `trigger` replaces the
default button trigger. Children are usually `<x-menu-item>`s.

### `<x-swap>`
daisyUI swap between two icons/labels bound to a boolean. Props: `true`, `false` (text),
`trueIcon` (`'o-sun'`), `falseIcon` (`'o-moon'`), `iconSize` (`'h-5 w-5'`).

### `<x-theme-toggle>`
Light/dark switch writing to `localStorage`. Props: `value`, `light` (`'Light'`), `dark` (`'Dark'`),
`lightTheme` (`'light'`), `darkTheme` (`'dark'`), `lightClass` (`'light'`), `darkClass` (`'dark'`),
`withLabel` (`false`).

---

## Layout and navigation

### `<x-main>`
The app shell: a daisyUI drawer with an optional sidebar. Props: `fullWidth`, `withNav`,
`collapseText` (`'Collapse'`), `collapseIcon` (`'o-bars-3-bottom-right'`), `collapsible`.
Slots: `sidebar`, `content`, `footer`.

The `sidebar` slot's own attributes drive behaviour: `drawer="..."` (the toggle input id),
`right`, `right-mobile`, `collapsible`, `collapse-icon`, `collapse-text`. Collapse state is stored in
the session via the package route `mary.toogle-sidebar` (that spelling is the package's).

### `<x-nav>`
Top bar. Props: `sticky`, `fullWidth`. Slots: `brand`, `actions`.

### `<x-menu>` / `<x-menu-item>` / `<x-menu-sub>` / `<x-menu-separator>` / `<x-menu-title>`
Sidebar navigation. `x-menu` props: `title`, `icon`, `iconClasses` (`'w-4 h-4'`), `separator`,
`activateByRoute`, `activeBgColor` (`'bg-base-300'`), `horizontal`. These are `@aware`, so children
inherit `horizontal`, `activateByRoute` and `activeBgColor`.

`x-menu-item` props: `title`, `icon`, `iconClasses`, `spinner`, `link`, `route`, `routeParams`
(array), `external`, `noWireNavigate`, `badge`, `badgeClasses`, `active`, `separator`, `hidden`,
`disabled`, `exact`. `hidden` returns an empty string, so it is a real conditional render, not CSS.
Active items get the `mary-active-menu` class.

`x-menu-sub` props: `title`, `icon`, `iconClasses`, `open`, `hidden`, `disabled`. It auto-opens when
a descendant carries `mary-active-menu`.

### `<x-header>`
Page title block. Props: `title`, `subtitle`, `separator`, `progressIndicator` (a `wire:target`
string, or `true` to target everything), `progressIndicatorClass` (`'progress-primary'`),
`withAnchor`, `size` (`'text-2xl'`), `weight` (`'font-extrabold'`), `useH1`, `icon`, `iconClasses`.
Slots: `middle`, `actions`.

```blade
<x-header :title="__('Volumes')" separator progress-indicator>
    <x-slot:actions>
        <x-button :label="__('New Volume')" icon="o-plus" class="btn-primary" link="{{ route('volumes.create') }}" />
    </x-slot:actions>
</x-header>
```

### `<x-card>`
Props: `title`, `subtitle`, `separator`, `shadow`, `progressIndicator`, `bodyClass` (`'null'`, a
package quirk: the default is the string `"null"`). Slots: `menu`, `actions`, `figure`.

### `<x-tabs>` / `<x-tab>`
`x-tabs` props: `labelClass`, `activeClass`, `contentClass`, `tabsClass`
(`'scrollbar-none flex-nowrap overflow-x-auto'`); the selected tab is bound with `wire:model` (or
`wire:model.live`). `x-tab` props: `name` (the value matched against the model), `label`, `icon`,
`disabled`, `hidden`, `badge`, `badgeClass`. Labels are teleported into the tab strip, so a `x-tab`
must be a descendant of `x-tabs`.

### `<x-steps>` / `<x-step>`
Wizard indicator. `x-steps` props: `vertical`, `stepsColor` (`'step-neutral'`), `stepperClasses`;
bound with `wire:model` to the current step number. `x-step` props: `step` (`int`, required),
`text` (required), `icon`, `stepClasses`, `dataContent`.

### `<x-collapse>` / `<x-accordion>`
`x-collapse` props: `name`, `collapsePlusMinus`, `separator`, `progressIndicator`, `noIcon`, `open`.
Slots: `heading`, `content`. `x-accordion` props: `noJoin`; it `wire:model`s the open child's `name`
and makes its `x-collapse` children behave as radios (`@aware(['noJoin'])`).

### `<x-drawer>`
Slide-over panel. Props: `right`, `title`, `subtitle`, `separator`, `withCloseButton`,
`closeOnEscape`, `withoutTrapFocus`, `withoutBackdropClose`. Slot: `actions`. Bound with
`wire:model` to a boolean. Internally it renders an `x-card`, so `class=` styles that card.

### `<x-breadcrumbs>`
Props: `items` (array of `['link' => ..., 'label' => ..., 'icon' => ..., 'tooltip' => ...]`),
`separator` (`'o-chevron-right'`), `linkItemClass`, `textItemClass`, `iconClass`, `separatorClass`,
`noWireNavigate`.

### `<x-hr>`
A divider that doubles as a `wire:loading` progress bar. Props: `target` (the `wire:target`).

---

## Data display

### `<x-table>`
See `table.md` for the full treatment. Props:

| Prop | Type | Default |
|---|---|---|
| `headers` | `array` (required) | |
| `rows` | `ArrayAccess\|array` (required) | |
| `striped` | `?bool` | `false` |
| `noHeaders` | `?bool` | `false` |
| `selectable` | `?bool` | `false` |
| `selectableKey` | `?string` | `'id'` |
| `expandable` | `?bool` | `false` |
| `expandableKey` | `?string` | `'id'` |
| `expandableCondition` | `mixed` | `null` |
| `link` | `?string` | `null` |
| `withPagination` | `?bool` | `false` |
| `perPage` | `?string` | `null` |
| `perPageValues` | `?array` | `[10, 20, 50, 100]` |
| `sortBy` | `?array` | `[]` |
| `sortByProperty` | `string` | `'sortBy'` |
| `rowDecoration` | `?array` | `[]` |
| `cellDecoration` | `?array` | `[]` |
| `showEmptyText` | `?bool` | `false` |
| `emptyText` | `mixed` | `'No records found.'` |
| `containerClass` | `string` | `'overflow-x-auto'` |
| `noHover` | `?bool` | `false` |
| `fluent` | `?bool` | `false` |

Slots: `actions` (a scoped slot receiving the row), `tr`, `cell`, `expansion` (scoped), `empty`,
`footer`. Combining `selectable` with `expandable` throws.

### `<x-pagination>`
Standalone paginator. Props: `rows` (required, a paginator), `perPageValues` (`[10, 20, 50, 100]`).
`x-table with-pagination` renders this for you.

### `<x-badge>`
Props: `value` (falls back to the default slot), `icon`, `iconRight`.

```blade
<x-badge :value="__('Failed')" class="badge-error badge-sm" />
```

### `<x-stat>`
KPI tile. Props: `value` (or default slot), `icon`, `color` (classes applied to the icon wrapper),
`title`, `description`, `tooltip`/`tooltipLeft`/`tooltipRight`/`tooltipBottom`.

### `<x-avatar>`
Props: `image`, `alt`, `placeholder`, `fallbackImage`. Slots: `title`, `subtitle`.

### `<x-list-item>`
Row for a list of models. Props: `item` (`object|array`, required), `avatar` (`'avatar'`),
`value` (`'name'`), `subValue` (`''`), `noSeparator`, `noHover`, `link`, `fallbackAvatar`.
Slots: `actions`, plus `value`/`sub-value`/`avatar` overrides via named slots.

### `<x-timeline-item>`
Props: `title` (required), `subtitle`, `description`, `icon`, `pending`, `first`, `last`,
`connectorPendingClass`, `connectorActiveClass`, `bulletActiveClass`, `bulletPendingClass`.

### `<x-icon>`
Props: `name` (required), `label` (renders text beside the icon).

Name resolution (`Icon::icon()`): a name containing a dot has the dot replaced by a hyphen and is
passed straight to blade-icons (`bi.database-fill-down` becomes `bi-database-fill-down`); a name
without a dot is prefixed with `heroicon-` (`o-server` becomes `heroicon-o-server`). Installed sets
and their prefixes: `heroicon` (blade-ui-kit/blade-heroicons, variants `o-` outline 24, `s-` solid
24, `m-` mini 20, `c-` micro 16), `bi` (davidhsianturi/blade-bootstrap-icons),
`devicon` (codeat3/blade-devicons). Size defaults to `w-5 h-5` unless the `class` already contains a
`w-` or `h-` utility.

### `<x-kbd>`
Wraps the slot in a `kbd`. No props beyond `id`.

### `<x-code>`, `<x-diff>`, `<x-chart>`, `<x-calendar>`, `<x-image-gallery>`, `<x-carousel>`, `<x-rating>`
JS-backed display widgets, none used in this repo.
`x-diff`: `old`, `new`, `fileName` (`'payload.json'`), `config`.
`x-chart`: no props beyond `id`; the Chart.js config is `wire:model`-bound and Chart.js must be
loaded globally.
`x-calendar`: `months` (`1`), `locale` (`'en-EN'`), `weekendHighlight`, `sundayStart`, `config`,
`events`.
`x-image-gallery`: `images` (required), `withArrows`, `withIndicators`, `imgCss`.
`x-carousel`: `slides` (required), `withoutIndicators`, `withoutArrows`, `autoplay`,
`interval` (`2000`); slot `content`.
`x-rating`: `total` (`5`).

---

## Feedback and overlays

### `<x-modal>`
A native `<dialog class="modal">`.

Props: `title`, `subtitle`, `boxClass` (classes for the inner `.modal-box`), `separator`,
`persistent` (removes the close button, the backdrop-click close and the Escape handler),
`withoutTrapFocus`. Slot: `actions` (rendered in `.modal-action`).

Two drive modes:
- `wire:model="showSomething"` on a boolean Livewire property. Mary entangles it `.live` and wires
  Escape and backdrop clicks to set it false.
- `id="myModal"` plus `<button onclick="myModal.showModal()">`, the pure-DOM route. Setting `id`
  disables the `wire:model` path entirely.

```blade
<x-modal wire:model="showDeleteModal" :title="__('Delete volume')" separator>
    {{ __('This cannot be undone.') }}

    <x-slot:actions>
        <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
        <x-button :label="__('Delete')" class="btn-error" wire:click="delete" spinner />
    </x-slot:actions>
</x-modal>
```

### `<x-alert>`
Props: `title`, `icon`, `description`, `shadow`, `dismissible`. Slot: `actions`. When `title` is
absent the default slot is the body. Colour comes from a daisyUI class: `class="alert-error"`.

### `<x-toast>`
Mounted once in `layouts/app.blade.php` and `layouts/auth.blade.php`; do not add another. Prop:
`position` (`'toast-top toast-end'`, the fallback when a toast carries no position). No `$id`, and
it forwards no attributes. Title and description are rendered with `x-html`.

Fire toasts with `App\Traits\Toast` (`$this->success(...)` etc.), never `Mary\Traits\Toast`, and
from Alpine with `successToast(title)`. The "Toasts" section of SKILL.md has the details.

### `<x-popover>`
Hover popover. Props: `position` (`'bottom'`), `offset` (`'10'`). Slots: `trigger`, `content`.
The root `<div>` accepts no attributes at all (see `attributes.md`).

### `<x-loading>`
A `<span class="loading">`. Size and style come from daisyUI classes:
`class="loading-spinner loading-sm"`.

### `<x-progress>` / `<x-progress-radial>`
`x-progress`: `value` (`0`), `max` (`100`), `indeterminate`. `x-progress-radial`: `value` (`0`),
`unit` (`'%'`).

### `<x-spotlight>`
Command palette. Props: `shortcut` (`'meta.g'`), `alternativeShortcut` (`'ctrl.g'`),
`searchText` (`'Search ...'`), `noResultsText` (`'Nothing found.'`), `url`, `fallbackAvatar`,
`noWireNavigate`. Slot: `append`. Backed by the `mary.spotlight` route, which resolves
`config('mary.components.spotlight.class')`, default `App\Support\Spotlight` (that class does not
exist in this repo, so the component is unusable as-is).
