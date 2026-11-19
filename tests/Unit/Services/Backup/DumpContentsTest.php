<?php

use App\Services\Backup\DTO\DumpContents;

test('fromPgRestoreList extracts nested schemas and tables from a TOC', function () {
    $toc = <<<'TOC'
;
; Archive created at 2026-01-01
; Selected TOC Entries:
;
5; 2615 2200 SCHEMA - public postgres
6; 2615 2201 SCHEMA - tb app
187; 1259 16385 TABLE public users postgres
188; 0 16385 TABLE DATA public users postgres
189; 2606 16390 CONSTRAINT public users users_pkey postgres
191; 1259 16395 TABLE tb orders app
192; 1259 16400 TABLE public users postgres
3431; 0 0 COMMENT budget TABLE s_saleorder root
TOC;

    $contents = DumpContents::fromPgRestoreList($toc);

    expect($contents->toArray())->toBe([
        'schemas' => [
            [
                'name' => 'public',
                'tables' => [
                    ['table_name' => 'users', 'comment' => null],
                ],
            ],
            [
                'name' => 'tb',
                'tables' => [
                    ['table_name' => 'orders', 'comment' => null],
                ],
            ],
        ],
    ]);
});

test('fromPgRestoreList ignores comments and non-table object types', function () {
    $contents = DumpContents::fromPgRestoreList(";\n3; 3079 16384 EXTENSION - plpgsql \n");

    expect($contents->schemas)->toBe([])
        ->and($contents->tableCount())->toBe(0);
});

test('withComments fills comment text keyed by schema.table', function () {
    $contents = DumpContents::fromPgRestoreList(<<<'TOC'
6; 2615 16977 SCHEMA - budget root
218; 1259 16978 TABLE budget s_saleorder root
219; 1259 16981 TABLE budget w_stock_drpt root
TOC)->withComments([
        'budget.s_saleorder' => '销售订单',
        'budget.w_stock_drpt' => '库存调拨',
    ]);

    expect($contents->toArray())->toBe([
        'schemas' => [
            [
                'name' => 'budget',
                'tables' => [
                    ['table_name' => 's_saleorder', 'comment' => '销售订单'],
                    ['table_name' => 'w_stock_drpt', 'comment' => '库存调拨'],
                ],
            ],
        ],
    ]);
});

test('toArray and fromArray round-trip', function () {
    $original = new DumpContents([
        [
            'name' => 'budget',
            'tables' => [
                ['table_name' => 's_saleorder', 'comment' => '销售订单'],
            ],
        ],
    ]);

    expect(DumpContents::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});
