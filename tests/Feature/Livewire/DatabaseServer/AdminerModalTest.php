<?php

use App\Livewire\DatabaseServer\AdminerModal;
use Livewire\Livewire;

test('the modal frames the application adminer route', function () {
    Livewire::test(AdminerModal::class)
        ->call('openModal', 'Production', 'o-circle-stack', 'MySQL')
        ->assertSet('adminerUrl', route('adminer'))
        ->assertSet('showModal', true);
});
