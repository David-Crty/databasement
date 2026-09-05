<?php

use App\Support\Changelog\ChangelogParser;

function parseChangelog(string $markdown): array
{
    return (new ChangelogParser('/nonexistent/CHANGELOG.md'))->parse($markdown);
}

test('it reads minor sections, patch tags, ordered groups and compare links', function () {
    $releases = parseChangelog(<<<'MD'
    # Changelog

    ## [Unreleased]

    ## [1.7] - 2026-09-05

    ### Fixed

    - `1.7.10` Second fix

    ### Added

    - `1.7.9` A feature
      that wraps onto a second line

    ### Notes

    - Custom section without a patch tag

    [Unreleased]: https://github.com/David-Crty/databasement/compare/v1.7.10...HEAD
    [1.7]: https://github.com/David-Crty/databasement/compare/v1.6.12...v1.7.10
    MD);

    expect($releases)->toHaveCount(2);

    expect($releases[0]->isUnreleased())->toBeTrue()
        ->and($releases[0]->version)->toBeNull()
        ->and($releases[0]->latestVersion)->toBeNull()
        ->and($releases[0]->sections)->toBe([]);

    $release = $releases[1];
    expect($release->version)->toBe('1.7')
        ->and($release->latestVersion)->toBe('1.7.10')
        ->and($release->date)->toBe('2026-09-05')
        ->and($release->url)->toBe('https://github.com/David-Crty/databasement/compare/v1.6.12...v1.7.10')
        ->and(array_keys($release->sections))->toBe(['Added', 'Fixed', 'Notes'])
        ->and($release->sections['Added'][0])->toBe(['version' => '1.7.9', 'html' => 'A feature that wraps onto a second line'])
        ->and($release->sections['Notes'][0]['version'])->toBeNull();
});

test('it falls back to the highest tagged patch when no compare link gives one', function () {
    $releases = parseChangelog(<<<'MD'
    ## [1.2]

    ### Fixed

    - `1.2.3` Older fix
    - `1.2.11` Newest fix
    MD);

    expect($releases[0]->latestVersion)->toBe('1.2.11');
});

test('it renders inline markdown and strips unsafe html', function () {
    $releases = parseChangelog(<<<'MD'
    ## [1.0] - 2026-03-20

    ### Added

    - `1.0.1` Set `APP_URL` ([#12](https://github.com/David-Crty/databasement/pull/12))
    - `1.0.1` <script>alert(1)</script> Bold **text** and [evil](javascript:alert(1))
    MD);

    $items = $releases[0]->sections['Added'];

    expect($items[0]['html'])->toBe('Set <code>APP_URL</code> (<a href="https://github.com/David-Crty/databasement/pull/12">#12</a>)')
        ->and($items[1]['html'])->not->toContain('<script>')
        ->and($items[1]['html'])->not->toContain('javascript:')
        ->and($items[1]['html'])->toContain('<strong>text</strong>');
});
