{{--
    Role picker shared by the invite, edit and "add existing user" forms.

    Expects:
      $roleOptions — array<array{id: string, name: string, abilities: list<string>}>
      $selected    — currently selected role id
      $model       — wire:model target to bind the selection to
--}}
<div>
    <label class="label label-text font-semibold mb-2">{{ __('Role in current organization') }}</label>
    <x-radio-card-group class="grid-cols-1" :label="__('Role in current organization')">
        @foreach($roleOptions as $option)
            <x-radio-card
                :active="$selected === $option['id']"
                :label="$option['name']"
                :value="$option['id']"
                horizontal
                wire:model.live="{{ $model }}"
            >
                <x-ability-badges :abilities="$option['abilities']" />
            </x-radio-card>
        @endforeach
    </x-radio-card-group>
</div>
