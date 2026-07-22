<?php

use App\Enums\DatabaseSelectionMode;
use App\Livewire\DatabaseServer\BackupForm;
use Illuminate\Validation\ValidationException;

test('validatePatternMode throws when the include pattern is not a valid regex', function () {
    expect(fn () => BackupForm::validatePatternMode(0, [
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '(unclosed',
    ]))->toThrow(ValidationException::class);
});
