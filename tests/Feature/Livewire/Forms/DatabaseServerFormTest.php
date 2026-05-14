<?php

use App\Livewire\Forms\DatabaseServerForm;
use Livewire\Component;

test('isNeo4j returns true only for Neo4j database type', function () {
    $component = new class extends Component
    {
        public function render(): string
        {
            return '';
        }
    };
    $form = new DatabaseServerForm($component, 'form');

    $form->database_type = 'neo4j';
    expect($form->isNeo4j())->toBeTrue();

    $form->database_type = 'mssql';
    expect($form->isNeo4j())->toBeFalse();
});
