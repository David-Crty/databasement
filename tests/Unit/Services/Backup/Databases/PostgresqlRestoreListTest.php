<?php

use App\Services\Backup\Databases\PostgresqlRestoreList;

// Shapes taken from pg_restore -l on PostgreSQL 17 (serial PK, FK, function, procedure, custom index).
$toc = <<<'TOC'
;
; Selected TOC Entries:
;
7; 2615 18773 SCHEMA - demo root
226; 1255 18794 PROCEDURE demo do_something() root
225; 1255 18793 FUNCTION demo hello() root
224; 1259 18782 TABLE demo orgs root
223; 1259 18781 SEQUENCE demo orgs_id_seq root
3452; 0 0 SEQUENCE OWNED BY demo orgs_id_seq root
222; 1259 18775 TABLE demo users root
221; 1259 18774 SEQUENCE demo users_id_seq root
3453; 0 0 SEQUENCE OWNED BY demo users_id_seq root
3290; 2604 18785 DEFAULT demo orgs id root
3289; 2604 18778 DEFAULT demo users id root
3445; 0 18782 TABLE DATA demo orgs root
3443; 0 18775 TABLE DATA demo users root
3454; 0 0 SEQUENCE SET demo orgs_id_seq root
3455; 0 0 SEQUENCE SET demo users_id_seq root
3295; 2606 18787 CONSTRAINT demo orgs orgs_pkey root
3293; 2606 18780 CONSTRAINT demo users users_pkey root
3291; 1259 18795 INDEX demo idx_users_org root
3296; 2606 18788 FK CONSTRAINT demo users users_org_fkey root
8; 2615 20000 SCHEMA - other root
300; 1259 20001 TABLE other notes root
TOC;

test('schema selection keeps constraints, sequences, functions and procedures', function () use ($toc) {
    $filtered = PostgresqlRestoreList::filter($toc, schemas: ['demo']);

    expect($filtered)
        ->toContain('SCHEMA - demo')
        ->toContain('FUNCTION demo hello()')
        ->toContain('PROCEDURE demo do_something()')
        ->toContain('TABLE demo users')
        ->toContain('CONSTRAINT demo users users_pkey')
        ->toContain('FK CONSTRAINT demo users users_org_fkey')
        ->toContain('SEQUENCE demo users_id_seq')
        ->toContain('DEFAULT demo users id')
        ->toContain('INDEX demo idx_users_org')
        ->toContain(';8; 2615 20000 SCHEMA - other root')
        ->toContain(';300; 1259 20001 TABLE other notes root')
        ->not->toContain("\n300; 1259 20001 TABLE other notes");
});

test('table include keeps schema-level objects and only the chosen tables dependents', function () use ($toc) {
    $filtered = PostgresqlRestoreList::filter($toc, includeTables: [
        ['schema' => 'demo', 'table' => 'users'],
    ]);

    expect($filtered)
        ->toContain('SCHEMA - demo')
        ->toContain('FUNCTION demo hello()')
        ->toContain('PROCEDURE demo do_something()')
        ->toContain('TABLE demo users')
        ->toContain('TABLE DATA demo users')
        ->toContain('CONSTRAINT demo users users_pkey')
        ->toContain('FK CONSTRAINT demo users users_org_fkey')
        ->toContain('SEQUENCE demo users_id_seq')
        ->toContain('SEQUENCE SET demo users_id_seq')
        ->toContain('INDEX demo idx_users_org')
        ->toContain(';224; 1259 18782 TABLE demo orgs root')
        ->toContain(';223; 1259 18781 SEQUENCE demo orgs_id_seq root')
        ->toContain(';3295; 2606 18787 CONSTRAINT demo orgs orgs_pkey root');
});

test('exclude comments out a table and its sequences indexes and constraints', function () use ($toc) {
    $filtered = PostgresqlRestoreList::filter($toc, schemas: ['demo'], excludeTables: [
        ['schema' => 'demo', 'table' => 'users'],
    ]);

    expect($filtered)
        ->toContain('TABLE demo orgs')
        ->toContain('FUNCTION demo hello()')
        ->toContain(';222; 1259 18775 TABLE demo users root')
        ->toContain(';221; 1259 18774 SEQUENCE demo users_id_seq root')
        ->toContain(';3293; 2606 18780 CONSTRAINT demo users users_pkey root')
        ->toContain(';3291; 1259 18795 INDEX demo idx_users_org root')
        ->toContain(';3296; 2606 18788 FK CONSTRAINT demo users users_org_fkey root');
});

test('summarize reports schemas and tables from a TOC', function () use ($toc) {
    expect(PostgresqlRestoreList::summarize($toc))->toBe([
        'entries' => 21,
        'schemas' => ['demo', 'other'],
        'tables' => ['demo.orgs', 'demo.users', 'other.notes'],
    ]);
});

test('summarize ignores commented-out TOC entries', function () use ($toc) {
    $filtered = PostgresqlRestoreList::filter($toc, includeTables: [
        ['schema' => 'demo', 'table' => 'users'],
    ]);

    $summary = PostgresqlRestoreList::summarize($filtered);

    expect($summary['schemas'])->toBe(['demo'])
        ->and($summary['tables'])->toBe(['demo.users'])
        ->and($summary['entries'])->toBeLessThan(PostgresqlRestoreList::entryCount($toc));
});
