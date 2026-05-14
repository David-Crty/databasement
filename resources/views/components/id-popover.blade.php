@props(['id'])

@php
    $popoverId = 'id-popover-' . md5($id);
    $shortId = \Illuminate\Support\Str::substr($id, -7);
@endphp

<span x-data="{
        timer: null,
        show() {
            clearTimeout(this.timer);
            const pop = document.getElementById('{{ $popoverId }}');
            const r = this.$refs.trigger.getBoundingClientRect();
            pop.style.top = (r.bottom + 4) + 'px';
            pop.style.left = r.left + 'px';
            pop.showPopover();
        },
        hide() {
            this.timer = setTimeout(() => {
                document.getElementById('{{ $popoverId }}').hidePopover();
            }, 200);
        }
     }">
    <span x-ref="trigger"
          class="cursor-help align-middle"
          x-on:mouseenter="show()"
          x-on:mouseleave="hide()">
        <kbd class="kbd kbd-xs font-mono">#{{ $shortId }}</kbd>
    </span>
    <div id="{{ $popoverId }}"
         popover="manual"
         x-on:mouseenter="show()"
         x-on:mouseleave="hide()"
         class="m-0 p-2 rounded-md bg-base-100 shadow-xl border border-base-content/10 text-sm font-mono"
         style="position: fixed;">
        {{ $id }}
    </div>
</span>
