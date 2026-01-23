<?php

use App\Models\User;

test('openapi spec is valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/docs/api.json');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'openapi',
        'info' => ['title', 'version'],
        'paths',
    ]);
});
