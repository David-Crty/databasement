<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;

test('Neo4j database type exposes metadata', function () {
    expect(DatabaseType::NEO4J->label())->toBe('Neo4j')
        ->and(DatabaseType::NEO4J->icon())->toBe('devicon.neo4j')
        ->and(DatabaseType::NEO4J->defaultPort())->toBe(7687)
        ->and(DatabaseType::NEO4J->dumpExtension())->toBe('cypher');
});

test('Neo4j database type rejects PDO connections', function () {
    DatabaseType::NEO4J->createPdo(new DatabaseServer);
})->throws(RuntimeException::class, 'Neo4j does not support PDO connections');

test('database type select options include Neo4j', function () {
    expect(DatabaseType::toSelectOptions())->toContain([
        'id' => 'neo4j',
        'name' => 'Neo4j',
    ]);
});
