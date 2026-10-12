<div class="relative">
    <x-menu-item
        :title="__('Agents')"
        icon="o-cpu-chip"
        :icon-classes="$this->outdatedAgents->isNotEmpty() ? 'text-warning' : null"
        :link="route('agents.index')"
        wire:navigate
        :active="$isActive"
    />
    @if($this->outdatedAgents->isNotEmpty())
        <span
            class="mary-hideable absolute right-3 top-1/2 -translate-y-1/2"
            x-data="{
                timer: null,
                show() {
                    clearTimeout(this.timer);
                    const r = this.$refs.trigger.getBoundingClientRect();
                    this.$refs.pop.style.top = r.top + 'px';
                    this.$refs.pop.style.left = (r.right + 8) + 'px';
                    this.$refs.pop.showPopover();
                },
                hide() {
                    this.timer = setTimeout(() => this.$refs.pop.hidePopover(), 200);
                }
            }"
        >
            <span x-ref="trigger" x-on:mouseenter="show()" x-on:mouseleave="hide()" class="flex cursor-help">
                <x-icon name="o-exclamation-triangle" class="w-4 h-4 text-warning" />
            </span>
            <div
                x-ref="pop"
                popover="manual"
                x-on:mouseenter="show()"
                x-on:mouseleave="hide()"
                class="m-0 p-3 w-64 rounded-md bg-base-100 shadow-xl border border-base-content/10 text-sm"
                style="position: fixed;"
            >
                <div class="font-semibold mb-2">
                    {{ trans_choice(':count agent needs an update|:count agents need an update', $this->outdatedAgents->count(), ['count' => $this->outdatedAgents->count()]) }}
                </div>
                <div class="space-y-1 mb-2">
                    @foreach($this->outdatedAgents as $agent)
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate">{{ $agent->name }}</span>
                            <span class="font-mono text-base-content/70 shrink-0">{{ $agent->versionLabel() }}</span>
                        </div>
                    @endforeach
                </div>
                @if($this->serverVersion)
                    <div class="text-base-content/70">
                        {{ __('Server runs :version', ['version' => $this->serverVersion]) }}
                    </div>
                @endif
            </div>
        </span>
    @endif
</div>
