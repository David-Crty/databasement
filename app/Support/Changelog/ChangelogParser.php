<?php

namespace App\Support\Changelog;

use Illuminate\Support\Facades\Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Reads CHANGELOG.md (one section per minor version, entries prefixed with the
 * patch in backticks) into release objects.
 *
 * Entries are rendered to inline HTML with raw HTML stripped and unsafe links
 * disabled, so the view can output them unescaped.
 */
final class ChangelogParser
{
    public const array SECTION_ORDER = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

    private readonly string $path;

    private ?MarkdownConverter $converter = null;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('CHANGELOG.md');
    }

    /**
     * @return list<ChangelogRelease>
     */
    public function releases(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $key = 'changelog.releases.'.md5($this->path).'.'.filemtime($this->path);

        // Only plain arrays are cached: a serialized object would fail to
        // rehydrate whenever the class shape changes or is not yet loadable.
        $cached = Cache::remember(
            $key,
            now()->addDay(),
            fn (): array => $this->toArrays((string) file_get_contents($this->path)),
        );

        return array_map(ChangelogRelease::fromArray(...), $cached);
    }

    /**
     * @return list<ChangelogRelease>
     */
    public function parse(string $markdown): array
    {
        return array_map(ChangelogRelease::fromArray(...), $this->toArrays($markdown));
    }

    /**
     * @return list<array{version: ?string, latestVersion: ?string, date: ?string, url: ?string, sections: array<string, list<array{version: ?string, html: string}>>}>
     */
    private function toArrays(string $markdown): array
    {
        /** @var list<array{label: string, date: ?string, sections: array<string, list<string>>}> $releases */
        $releases = [];
        /** @var array<string, string> $links */
        $links = [];
        $current = null;
        $section = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^## \[(?<label>[^\]]+)\](?: - (?<date>\d{4}-\d{2}-\d{2}))?\s*$/', $line, $m)) {
                if ($current !== null) {
                    $releases[] = $current;
                }
                $current = ['label' => $m['label'], 'date' => $m['date'] ?? null, 'sections' => []];
                $section = null;

                continue;
            }

            if (preg_match('/^\[(?<label>[^\]]+)\]: (?<url>\S+)\s*$/', $line, $m)) {
                $links[$m['label']] = $m['url'];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^### (?<name>\w+)\s*$/', $line, $m)) {
                $section = $m['name'];
                $current['sections'][$section] ??= [];

                continue;
            }

            if ($section === null) {
                continue;
            }

            if (preg_match('/^[-*] (?<item>\S.*)$/', $line, $m)) {
                $current['sections'][$section][] = $m['item'];
            } elseif (preg_match('/^\s{2,}(?<rest>\S.*)$/', $line, $m) && $current['sections'][$section] !== []) {
                $last = array_key_last($current['sections'][$section]);
                $current['sections'][$section][$last] .= ' '.$m['rest'];
            }
        }

        if ($current !== null) {
            $releases[] = $current;
        }

        return array_map(function (array $release) use ($links): array {
            $unreleased = strcasecmp($release['label'], 'unreleased') === 0;
            $url = $links[$release['label']] ?? null;
            $sections = $this->renderSections($release['sections']);

            return [
                'version' => $unreleased ? null : $release['label'],
                'latestVersion' => $unreleased ? null : $this->latestVersion($release['label'], $url, $sections),
                'date' => $release['date'],
                'url' => $url,
                'sections' => $sections,
            ];
        }, $releases);
    }

    /**
     * @param  array<string, list<string>>  $sections
     * @return array<string, list<array{version: ?string, html: string}>>
     */
    private function renderSections(array $sections): array
    {
        $ordered = [];

        foreach ([...self::SECTION_ORDER, ...array_keys($sections)] as $name) {
            if (isset($ordered[$name]) || empty($sections[$name])) {
                continue;
            }

            foreach ($sections[$name] as $raw) {
                $version = null;

                if (preg_match('/^`(?<version>\d+\.\d+\.\d+)`\s+(?<text>\S.*)$/s', $raw, $m)) {
                    $version = $m['version'];
                    $raw = $m['text'];
                }

                $ordered[$name][] = ['version' => $version, 'html' => $this->inline($raw)];
            }
        }

        return $ordered;
    }

    /**
     * The newest patch a section records: read from its compare link, else the
     * highest patch tag among its entries, else the label when it is a full version.
     *
     * @param  array<string, list<array{version: ?string, html: string}>>  $sections
     */
    private function latestVersion(string $label, ?string $url, array $sections): ?string
    {
        if ($url !== null && preg_match('/\.{3}v(?<version>\d+\.\d+\.\d+)$/', $url, $m)) {
            return $m['version'];
        }

        $latest = null;

        foreach ($sections as $entries) {
            foreach ($entries as $entry) {
                if ($entry['version'] !== null && ($latest === null || version_compare($entry['version'], $latest, '>'))) {
                    $latest = $entry['version'];
                }
            }
        }

        if ($latest === null && preg_match('/^\d+\.\d+\.\d+$/', $label)) {
            return $label;
        }

        return $latest;
    }

    private function inline(string $markdown): string
    {
        $this->converter ??= $this->makeConverter();

        return trim((string) $this->converter->convert($markdown));
    }

    private function makeConverter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new InlinesOnlyExtension);

        return new MarkdownConverter($environment);
    }
}
