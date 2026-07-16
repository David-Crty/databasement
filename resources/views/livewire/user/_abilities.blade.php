@can('manage-users')
    <x-collapse :open="! empty($form->abilities)">
        <x-slot:heading>
            <x-icon name="o-key" class="w-4 h-4" />
            {{ __('Additional abilities') }}
        </x-slot:heading>
        <x-slot:content class="space-y-3">
            <p class="text-sm text-base-content/60">{{ __('Granted to this user on top of their role, in this organization only.') }}</p>
            <x-ability-toggles :groups="$abilityGroups" model="form.abilities" />
        </x-slot:content>
    </x-collapse>
@endcan
