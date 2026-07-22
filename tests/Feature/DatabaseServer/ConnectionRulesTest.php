<?php

use App\Enums\DatabaseType;
use App\Livewire\DatabaseServer\Connection\ConnectionRules;

test('every database type resolves connection rules and a connection partial', function () {
    foreach (DatabaseType::cases() as $type) {
        expect(ConnectionRules::for($type))->toBeInstanceOf(ConnectionRules::class);
        expect(view()->exists('livewire.database-server.connection.'.$type->value))
            ->toBeTrue("Missing connection partial for database type [{$type->value}]");
    }
});
