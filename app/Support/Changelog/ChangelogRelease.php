<?php

namespace App\Support\Changelog;

/**
 * One changelog section: a minor version (1.7) whose entries each carry the
 * patch that shipped them, or the Unreleased block.
 */
final readonly class ChangelogRelease
{
    /**
     * @param  array<string, list<array{version: ?string, html: string}>>  $sections  Section name => HTML-rendered entries in Keep a Changelog order
     */
    public function __construct(
        public ?string $version,
        public ?string $latestVersion,
        public ?string $date,
        public ?string $url,
        public array $sections,
    ) {}

    /**
     * @param  array{version: ?string, latestVersion: ?string, date: ?string, url: ?string, sections: array<string, list<array{version: ?string, html: string}>>}  $release
     */
    public static function fromArray(array $release): self
    {
        return new self(
            version: $release['version'],
            latestVersion: $release['latestVersion'],
            date: $release['date'],
            url: $release['url'],
            sections: $release['sections'],
        );
    }

    public function isUnreleased(): bool
    {
        return $this->version === null;
    }

    /**
     * "1.7.10" => "1.7"
     */
    public static function minorOf(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
