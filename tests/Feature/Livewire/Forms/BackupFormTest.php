<?php

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Livewire\Forms\BackupForm;
use Illuminate\Validation\ValidationException;

test('validatePatternMode throws when pattern regex is invalid', function () {
    expect(fn () => BackupForm::validatePatternMode(0, [
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '(unclosed',
    ]))->toThrow(ValidationException::class);
});

test('validatePatternMode is a no-op when mode is not pattern', function () {
    BackupForm::validatePatternMode(0, [
        'database_selection_mode' => DatabaseSelectionMode::All->value,
        'database_include_pattern' => '(unclosed',
    ]);
})->throwsNoExceptions();

test('validatePatternMode is a no-op when pattern is empty', function () {
    BackupForm::validatePatternMode(0, [
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '',
    ]);
})->throwsNoExceptions();

test('selectionSummary returns null for SQLite without paths', function () {
    expect(BackupForm::selectionSummary(
        ['database_names' => []],
        DatabaseType::SQLITE,
    ))->toBeNull();
});

test('selectionSummary counts SQLite file paths', function () {
    expect(BackupForm::selectionSummary(
        ['database_names' => ['/data/a.sqlite', '/data/b.sqlite']],
        DatabaseType::SQLITE,
    ))->toBe('2 files');
});

test('selectionSummary falls back to comma-separated input when the array is empty', function () {
    // This is the path taken when availableDatabases could not be loaded and
    // the user is still typing the list into the free-text field.
    expect(BackupForm::selectionSummary([
        'database_selection_mode' => DatabaseSelectionMode::Selected->value,
        'database_names' => [],
        'database_names_input' => 'db1, db2, db3',
    ], DatabaseType::MYSQL))->toBe('3 databases');
});

test('selectionSummary returns null when selected mode has nothing selected', function () {
    expect(BackupForm::selectionSummary([
        'database_selection_mode' => DatabaseSelectionMode::Selected->value,
        'database_names' => [],
        'database_names_input' => '',
    ], DatabaseType::MYSQL))->toBeNull();
});

test('selectionSummary returns null for pattern mode without a pattern', function () {
    expect(BackupForm::selectionSummary([
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '',
    ], DatabaseType::MYSQL))->toBeNull();
});

test('selectionSummary renders the pattern when present', function () {
    expect(BackupForm::selectionSummary([
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '^prod_',
    ], DatabaseType::MYSQL))->toBe('databases matching /^prod_/i');
});
