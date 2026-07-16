@props(['groups', 'model'])

{{-- Shared ability toggle grid. `model` is the wire:model target so it can bind
     to a component property (Roles screen: "abilities") or a form property
     (user form: "form.abilities"). --}}
<div class="space-y-4">
    @foreach($groups as $group => $groupAbilities)
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-base-content/50 mb-2">{{ $group }}</div>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach($groupAbilities as $ability)
                    <x-checkbox
                        wire:model="{{ $model }}"
                        value="{{ $ability->value }}"
                        :label="$ability->label()"
                        :hint="$ability->description()"
                    />
                @endforeach
            </div>
        </div>
    @endforeach
</div>
