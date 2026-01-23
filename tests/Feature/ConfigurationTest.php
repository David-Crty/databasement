<?php

use App\Models\User;

test('configuration page displays expected settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('configuration.index'))
        ->assertOk()
        ->assertSee('Configuration')
        ->assertSee('BACKUP_WORKING_DIRECTORY')
        ->assertSee('MYSQL_CLI_TYPE');
});
