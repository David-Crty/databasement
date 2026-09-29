# Where your attributes actually land

Mary components are single PHP classes whose `render()` returns an inline Blade heredoc. Anything you
pass that is not a constructor parameter goes into `$attributes`, and each component decides where to
emit it. That decision is not uniform, and it is the source of most "why did my class do nothing"
moments.

Verify a specific component by opening
`vendor/robsontenorio/mary/src/View/Components/<Name>.php` and finding where `{{ $attributes ... }}`
appears inside the heredoc.

## Components that forward nothing at all

These nine never emit `$attributes` onto any element. Passing `class`, `id`, `x-data` or anything
else to them is silently dropped.

`x-popover`, `x-main`, `x-toast`, `x-spotlight`, `x-menu-sub`, `x-timeline-item`, `x-calendar`,
`x-diff`, `x-markdown`.

(`x-markdown` still reads `$attributes` for `wire:model` and `required`; it just never renders the
bag, so `class` on it is lost.)

The workaround is a wrapper element you control:

```blade
{{-- x-popover does not forward attributes to its root, so the shrink wrapper lives outside it --}}
<div class="min-w-0">
    <x-popover position="bottom-start">
        <x-slot:trigger>...</x-slot:trigger>
        <x-slot:content class="max-w-sm text-sm whitespace-pre-line">...</x-slot:content>
    </x-popover>
</div>
```

Two of them still accept classes on their **slots**, which is often enough:
- `x-popover`: `<x-slot:trigger class="...">` and `<x-slot:content class="...">`.
- `x-main`: `<x-slot:sidebar class="...">`, `<x-slot:content class="...">`, and the sidebar slot's
  `drawer`, `right`, `right-mobile`, `collapsible`, `collapse-icon`, `collapse-text` attributes.

## Components that forward to the root element

The common case. `class=` merges with the component's own daisyUI classes on the outermost element:
`x-button` (`<button>` / `<a>`), `x-alert`, `x-badge`, `x-card`, `x-header`, `x-form`, `x-modal`
(`<dialog>`), `x-menu` (`<ul>`), `x-menu-item` (`<a>`), `x-menu-title` (`<li>`), `x-nav`, `x-stat`,
`x-list-item`, `x-hr`, `x-loading`, `x-progress`, `x-progress-radial`, `x-collapse`, `x-accordion`,
`x-breadcrumbs` (`<ul>`), `x-kbd`, `x-chart`, `x-icon` (the `<svg>`).

`x-menu-separator` is a special case: it always emits an `<hr>` and only renders an attribute-carrying
`<li>` when `title` is set, so `<x-menu-separator />` on its own accepts nothing.

## Components that forward to an inner element

Here the outermost element is a bare wrapper that receives nothing, so layout classes
(`flex-1`, `w-full`, `col-span-2`) applied to the tag do not size the box the parent flex/grid sees.

| Component | `class` lands on |
|---|---|
| `x-input`, `x-password`, `x-datepicker`, `x-datetime`, `x-colorpicker`, `x-tags` | the `<label class="input">` inside the fieldset |
| `x-select`, `x-select-group`, `x-choices`, `x-choices-offline` | the `<label class="select">` |
| `x-textarea`, `x-code` | the `<textarea class="textarea">` or its wrapper |
| `x-file` | the `<input class="file-input">` |
| `x-checkbox`, `x-toggle`, `x-radio` | the `<input>` itself |
| `x-range` | the `<input class="range">` |
| `x-group` | each `join-item btn` radio |
| `x-pin` | each digit `<input>` |
| `x-table` | the `<table>`, not the `overflow-x-auto` container (that is `containerClass`) |
| `x-dropdown` | the default `<summary class="btn">` trigger, and only when no `trigger` slot is given |
| `x-tabs` | the tab strip div, not the outer wrapper |
| `x-tab` | the `tab-content` panel |
| `x-drawer` | the inner `x-card` |
| `x-avatar` | the round image div, not the outer flex row |
| `x-errors` | the `alert alert-error` div inside the wrapper |
| `x-pagination` | an empty spacer div above the controls, so a class here does almost nothing |
| `x-theme-toggle` | the inner `<label class="swap">` |
| `x-step` | the visible content div |
| `x-steps` | the step-panels div below the step indicator |
| `x-carousel`, `x-image-gallery` | the inner viewport div |
| `x-rating` | each star `<input>` |
| `x-signature`, `x-image-library` | an inner control wrapper |

So this does not work:

```blade
{{-- the input stays its natural width: the flex child is Mary's untouched wrapper div --}}
<div class="flex gap-2">
    <x-input wire:model="newToken" readonly class="w-full font-mono text-sm" />
    <x-button icon="o-clipboard-document" class="btn-primary" />
</div>
```

and this does:

```blade
<div class="flex gap-2">
    <div class="flex-1">
        <x-input wire:model="newToken" readonly class="w-full font-mono text-sm" />
    </div>
    <x-button icon="o-clipboard-document" class="btn-primary" />
</div>
```

## Beating Mary's own classes

`$attributes->class([...])` merges rather than replaces, so a size modifier can lose to the base
class Mary hardcodes (`input w-full`, `select w-full`). Prefix with Tailwind's `!` important marker
when it does:

```blade
<x-input :placeholder="__('Search...')" wire:model.live.debounce="search" clearable
         icon="o-magnifying-glass" class="!input-sm w-48" />
<x-select :placeholder="__('All Types')" placeholder-value="" wire:model.live="dbTypeFilter"
          :options="$dbTypeOptions" class="!select-sm w-40" />
```

## Duplicate `placeholder`

`x-input`, `x-textarea`, `x-choices`, `x-choices-offline`, `x-tags`, `x-datepicker` and
`x-colorpicker` hardcode `placeholder="{{ $attributes->get('placeholder') }} "` (with a trailing
space, which the floating-label CSS needs) and then merge `$attributes`, which emits `placeholder`
again. The rendered HTML therefore carries two `placeholder` attributes. Browsers keep the first, the
behaviour is correct, and there is nothing to fix. Do not "clean it up" by dropping the prop.
