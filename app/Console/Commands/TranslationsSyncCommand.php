<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TranslationsSyncCommand extends Command
{
    protected $signature = 'translations:sync
        {--check : Report only, write nothing, and fail when a locale is out of sync}';

    protected $description = 'Align lang/{locale}.json with lang/en.json: prune removed keys, normalise formatting, report gaps';

    public function handle(): int
    {
        $sourcePath = lang_path('en.json');

        if (! file_exists($sourcePath)) {
            $this->error('lang/en.json is missing. Run `php artisan translatable:export en` first.');

            return self::FAILURE;
        }

        $source = $this->read($sourcePath);
        $check = (bool) $this->option('check');
        $outOfSync = false;

        $this->line(sprintf('Source: lang/en.json (%d keys)', count($source)));

        foreach ($this->targetLocales() as $locale) {
            $path = lang_path("{$locale}.json");

            if (! file_exists($path)) {
                $this->warn("  {$locale}: lang/{$locale}.json is missing");
                $outOfSync = true;

                continue;
            }

            $existing = $this->read($path);
            $translations = $this->prune($existing, $source);
            $missing = count($source) - count($translations);
            $stale = count($existing) - count($translations);

            // Comparing the rendered file rather than the parsed array also catches the
            // things parsing hides: key order, the stripped banner, the indent width.
            $rendered = $this->render($translations);
            $needsWrite = $rendered !== file_get_contents($path);

            if (! $check && $needsWrite) {
                file_put_contents($path, $rendered);
            }

            $this->report($locale, $missing, $stale, $needsWrite, $check);

            if ($missing > 0 || $needsWrite) {
                $outOfSync = true;
            }
        }

        return $check && $outOfSync ? self::FAILURE : self::SUCCESS;
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
     * Keep only the keys the source still has, in the source's order.
     *
     * Keys are never added, which is the whole reason this exists rather than a second
     * `translatable:export`: the exporter fills a gap with the English text, and the
     * translator decides a key is done purely by its presence, so an added key is a
     * string that would never be translated. Leaving it absent keeps "missing" meaning
     * "still needs translating".
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

    private function report(string $locale, int $missing, int $stale, bool $needsWrite, bool $check): void
    {
        $summary = sprintf('  %s: %d missing', $locale, $missing);

        if ($stale > 0) {
            $summary .= sprintf($check ? ', %d stale' : ', %d stale removed', $stale);
        }

        if ($needsWrite && $stale === 0) {
            $summary .= $check ? ', needs formatting' : ', reformatted';
        }

        $missing === 0 && ! $needsWrite ? $this->info($summary) : $this->warn($summary);
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

        // The translator writes a "do not edit" banner stamped with the current time into
        // every file it saves. Left in place, it dirties all four files on every run.
        unset($decoded['_comment']);

        return $decoded;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function render(array $translations): string
    {
        $json = json_encode(
            $translations,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $this->indentWithTwoSpaces($json)."\n";
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
