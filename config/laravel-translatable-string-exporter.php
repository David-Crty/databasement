<?php

return [
    // Directories to search in.
    'directories' => [
        'app',
        'resources',
    ],

    // Directories to exclude from search, relative to the ones listed in 'directories'.
    'excluded-directories' => [
    ],

    // File Patterns to search for.
    'patterns' => [
        '*.php',
    ],

    // Indicates whether new lines are allowed in translations.
    'allow-newlines' => false,

    // Translation function names or a custom transform function.
    'functions' => [
        '__',
        'trans_choice',
        '@lang',
    ],

    // Indicates whether you need to sort the translations alphabetically
    // by original strings (keys).
    'sort-keys' => true,

    // Indicates whether keys from the persistent-strings file should be also added
    // to translation files automatically on export if they don't yet exist there.
    'add-persistent-strings-to-translations' => true,

    // Indicates whether it's necessary to exclude Laravel translation keys
    // from the resulting language file if they have corresponding translations
    // in the given language.
    'exclude-translation-keys' => false,

    // Indicates whether you need to put untranslated strings
    // at the top of a translation file.
    'put-untranslated-strings-at-the-top' => false,
];
