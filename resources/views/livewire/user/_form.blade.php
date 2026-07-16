{{--
    Shared user fields for the invite (create) and edit forms. Both bind to the
    component's `$form` (UserForm). Differences are passed in:

      $roleOptions   — role picker options (see UserForm::roleOptions())
      $abilityGroups — grouped ability catalogue for the direct-abilities grid
      $isOAuthUser   — when true, the email is locked (SSO-managed). Defaults false.
--}}
@php($isOAuthUser = $isOAuthUser ?? false)

<x-input
    wire:model="form.name"
    :label="__('Name')"
    :placeholder="__('Full name')"
    icon="o-user"
    required
/>

<x-input
    wire:model="form.email"
    :label="__('Email')"
    type="email"
    placeholder="email@example.com"
    icon="o-envelope"
    :disabled="$isOAuthUser"
    :hint="$isOAuthUser ? __('Email cannot be changed for SSO/OAuth users.') : null"
    required
/>

@if(auth()->user()->isSuperAdmin())
    <x-checkbox
        wire:model="form.superAdmin"
        :label="__('Super Admin')"
        :hint="__('Super admins can access all organizations and manage global settings.')"
    />
@endif

@include('livewire.user._role-cards', ['roleOptions' => $roleOptions, 'selected' => $form->role, 'model' => 'form.role'])

@include('livewire.user._abilities', ['abilityGroups' => $abilityGroups])
