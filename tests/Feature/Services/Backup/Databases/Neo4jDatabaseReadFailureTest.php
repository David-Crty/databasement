<?php

namespace App\Services\Backup\Databases {
    final class Neo4jDatabaseReadFailureStub
    {
        public static bool $failReads = false;
    }

    function fread($stream, int $length): string|false
    {
        if (Neo4jDatabaseReadFailureStub::$failReads) {
            return false;
        }

        return \fread($stream, $length);
    }
}

namespace {
    use App\Services\Backup\Databases\Neo4jDatabase;
    use App\Services\Backup\Databases\Neo4jDatabaseReadFailureStub;

    test('restore throws when reading cypher file fails', function () {
        $db = new Neo4jDatabase;
        $method = new ReflectionMethod($db, 'readCypherStatements');
        $method->setAccessible(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'CREATE (:Person);');
        rewind($stream);

        Neo4jDatabaseReadFailureStub::$failReads = true;

        try {
            iterator_to_array($method->invoke($db, $stream));
        } finally {
            Neo4jDatabaseReadFailureStub::$failReads = false;
            fclose($stream);
        }
    })->throws(RuntimeException::class, 'Failed while reading Neo4j restore file');
}
