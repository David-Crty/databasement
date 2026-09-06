<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TranslationsSyncCommand extends Command
{
    protected $signature = 'translations:sync
        {--check : Report only, write nothing, and fail when a locale is incomplete}';

    protected $description = 'Align lang/{locale}.json with lang/en.json: prune removed keys, normalise formatting, report gaps';

    /**
     * Characters Blade escapes into HTML entities, which then render verbatim in the UI.
     *
     * @var array<string, string>
     */
    private const ARTIFACTS = [
        "'" => 'ASCII apostrophe',
        '"' => 'ASCII double quote',
        '&' => 'ampersand',
    ];

    public function handle(): int
    {
        $sourcePath = lang_path('en.json');

        if (! file_exists($sourcePath)) {
            $this->error('lang/en.json is missing. Run `php artisan translatable:export en` first.');

            return self::FAILURE;
        }

        $source = $this->read($sourcePath);
        $check = (bool) $this->option('check');
        $incomplete = false;

        $this->line(sprintf('Source: lang/en.json (%d keys)', count($source)));

        foreach ($this->targetLocales() as $locale) {
            $path = lang_path("{$locale}.json");

            if (! file_exists($path)) {
                $this->warn("  {$locale}: lang/{$locale}.json is missing");
                $incomplete = true;

                continue;
            }

            $existing = $this->read($path);
            $translations = $this->prune($existing, $source);
            $missing = array_diff_key($source, $translations);
            $artifacts = $this->artifacts($translations, $source);
            $broken = $this->structuralDrift($translations, $source);

            if (! $check && $translations !== $existing) {
                $this->write($path, $translations);
            }

            $this->report($locale, count($existing) - count($translations), $missing, $artifacts, $broken);

            if ($missing !== [] || $artifacts !== [] || $broken !== []) {
                $incomplete = true;
            }
        }

        foreach ($this->strayFiles() as $stray) {
            $this->warn("  {$stray} is not a configured locale. Remove it, or add the locale to config/app.php.");
            $incomplete = true;
        }

        return $check && $incomplete ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A mistyped --locale writes a whole new lang/<junk>.json that nothing reads and
     * nobody notices. Everything in lang/ should be the source, the persistent-strings
     * input, or a locale config/app.php actually offers.
     *
     * @return array<int, string>
     */
    private function strayFiles(): array
    {
        $expected = [...array_keys(config('app.available_locales', [])), 'persistent-strings'];

        $stray = [];

        foreach (glob(lang_path('*.json')) ?: [] as $path) {
            if (! in_array(basename($path, '.json'), $expected, true)) {
                $stray[] = 'lang/'.basename($path);
            }
        }

        return $stray;
    }

    /**
     * @return array<int, string>
     */
    private function targetLocales(): array
    {
        $locales = array_map(strval(...), array_keys(config('app.available_locales', [])));

        return array_values(array_diff($locales, ['en']));
    }

    /**
     * Keep only the keys the source still has, in the source's order. Keys are never
     * added: a missing key must stay missing so it reads as untranslated rather than
     * as an English string somebody signed off on.
     *
     * @param  array<string, string>  $translations
     * @param  array<string, string>  $source
     * @return array<string, string>
     */
    private function prune(array $translations, array $source): array
    {
        $pruned = [];

        foreach ($source as $key => $ignored) {
            if (array_key_exists($key, $translations)) {
                $pruned[$key] = $translations[$key];
            }
        }

        return $pruned;
    }

    /**
     * A character the English source already carries is not something the translation
     * introduced, so only flag the ones the translator added on its own.
     *
     * @param  array<string, string>  $translations
     * @param  array<string, string>  $source
     * @return array<string, array<int, string>>
     */
    private function artifacts(array $translations, array $source): array
    {
        $found = [];

        foreach ($translations as $key => $value) {
            foreach (self::ARTIFACTS as $character => $label) {
                if (str_contains($value, $character) && ! str_contains($source[$key] ?? '', $character)) {
                    $found[$key][] = $label;
                }
            }
        }

        return $found;
    }

    /**
     * A translation must keep the shape Laravel reads back. Two things can break it:
     * a `:placeholder` disappearing, and `trans_choice` branch ranges that no longer
     * cover the counts the source covered. Branch *count* is deliberately not checked:
     * the translator mandates three branches for Chinese ("{1} 一個|{2} 兩個|[3,*] :count 個"),
     * which is both valid and better Chinese than reusing a digit.
     *
     * @param  array<string, string>  $translations
     * @param  array<string, string>  $source
     * @return array<string, string>
     */
    private function structuralDrift(array $translations, array $source): array
    {
        $drift = [];

        foreach ($translations as $key => $value) {
            $original = $source[$key] ?? '';

            $expected = $this->placeholders($original);
            $actual = $this->placeholders($value);

            if (array_diff($expected, $actual) !== []) {
                $drift[$key] = sprintf(
                    'placeholders: expected %s, got %s',
                    implode(' ', $expected),
                    implode(' ', $actual) ?: '(none)'
                );

                continue;
            }

            $uncovered = $this->uncoveredCounts($original, $value);

            if ($uncovered !== null) {
                $drift[$key] = $uncovered;
            }
        }

        return $drift;
    }

    /**
     * Describe the first count the source resolves but the translation does not.
     * Returns null when the translation covers everything the source did, including
     * when neither side carries explicit conditions.
     */
    private function uncoveredCounts(string $original, string $translated): ?string
    {
        $sourceRanges = $this->ranges($original);
        $translatedRanges = $this->ranges($translated);

        if ($sourceRanges === null) {
            return null;
        }

        if ($translatedRanges === null) {
            return 'plural ranges: the source has explicit conditions, the translation has none';
        }

        foreach ($sourceRanges as [$from, $to]) {
            foreach ([$from, min($to, $from + 1), $to] as $count) {
                if (! $this->covers($translatedRanges, $count)) {
                    return sprintf('plural ranges: no branch matches a count of %d', $count);
                }
            }
        }

        return null;
    }

    /**
     * Parse the `{n}` and `[a,b]` conditions Laravel's MessageSelector reads, as
     * inclusive integer ranges. Null when any branch has no condition, since Laravel
     * then falls back to positional plural selection and ranges say nothing.
     *
     * @return array<int, array{int, int}>|null
     */
    private function ranges(string $text): ?array
    {
        $ranges = [];

        foreach (explode('|', $text) as $branch) {
            if (preg_match('/^\s*\{(\d+)\}/', $branch, $exact) === 1) {
                $ranges[] = [(int) $exact[1], (int) $exact[1]];

                continue;
            }

            if (preg_match('/^\s*\[(\d+),\s*(\d+|\*)\]/', $branch, $span) === 1) {
                $ranges[] = [(int) $span[1], $span[2] === '*' ? PHP_INT_MAX : (int) $span[2]];

                continue;
            }

            return null;
        }

        return $ranges;
    }

    /**
     * @param  array<int, array{int, int}>  $ranges
     */
    private function covers(array $ranges, int $count): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($count >= $from && $count <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $text, $matches);

        $placeholders = array_unique($matches[0]);
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @param  array<string, string>  $missing
     * @param  array<string, array<int, string>>  $artifacts
     * @param  array<string, string>  $broken
     */
    private function report(string $locale, int $removed, array $missing, array $artifacts, array $broken): void
    {
        $summary = sprintf('  %s: %d missing', $locale, count($missing));

        if ($removed > 0) {
            $summary .= sprintf(', %d stale removed', $removed);
        }

        if ($artifacts !== []) {
            $summary .= sprintf(', %d with encoding artifacts', count($artifacts));
        }

        if ($broken !== []) {
            $summary .= sprintf(', %d structurally broken', count($broken));
        }

        $missing === [] && $artifacts === [] && $broken === [] ? $this->info($summary) : $this->warn($summary);

        foreach (array_slice(array_keys($missing), 0, 5) as $key) {
            $this->line("      missing: {$key}");
        }

        if (count($missing) > 5) {
            $this->line(sprintf('      ... and %d more', count($missing) - 5));
        }

        foreach ($artifacts as $key => $labels) {
            $this->line(sprintf('      %s: %s', implode(', ', $labels), $key));
        }

        foreach ($broken as $key => $issue) {
            $this->line(sprintf('      %s: %s', $issue, $key));
        }
    }

    /**
     * @return array<string, string>
     */
    private function read(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("Unable to decode {$path} as JSON.");
        }

        // The translator writes a "do not edit" banner into every file it saves.
        unset($decoded['_comment']);

        return $decoded;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function write(string $path, array $translations): void
    {
        $json = json_encode(
            $translations,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        file_put_contents($path, $this->indentWithTwoSpaces($json)."\n");
    }

    private function indentWithTwoSpaces(string $json): string
    {
        return (string) preg_replace_callback(
            '/^ +/m',
            fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)),
            $json
        );
    }
}
