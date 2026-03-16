<?php

namespace App\Support;

class GitInfo
{
    public static function getVersion(): ?string
    {
        return config('app.version') ?: null;
    }

    public static function getVersionLabel(): ?string
    {
        $version = self::getVersion();
        $commitHash = self::getCommitHash();

        if ($version && $commitHash) {
            return "v{$version} ({$commitHash})";
        }

        if ($version) {
            return "v{$version}";
        }

        return $commitHash;
    }

    public static function getCommitHash(): ?string
    {
        $configuredHash = config('app.commit_hash');
        if ($configuredHash) {
            return substr($configuredHash, 0, 7);
        }

        $hash = self::runGitCommand('rev-parse --short HEAD');

        return $hash ?: null;
    }

    public static function getFullCommitHash(): ?string
    {
        $configuredHash = config('app.commit_hash');
        if ($configuredHash) {
            return $configuredHash;
        }

        $hash = self::runGitCommand('rev-parse HEAD');

        return $hash ?: null;
    }

    public static function getGitHubRepo(): string
    {
        return config('app.github_repo', 'https://github.com/David-Crty/databasement');
    }

    public static function getGitHubRepoShort(): string
    {
        return trim(str_replace('https://', '', self::getGitHubRepo()), '/');
    }

    public static function getCommitUrl(): ?string
    {
        $fullHash = self::getFullCommitHash();
        if (! $fullHash) {
            return null;
        }

        return self::getGitHubRepo().'/commit/'.$fullHash;
    }

    public static function getNewIssueUrl(): string
    {
        return self::getGitHubRepo().'/issues/new';
    }

    private static function runGitCommand(string $command): ?string
    {
        $output = [];
        $exitCode = 0;
        exec('git -C '.escapeshellarg(base_path())." {$command} 2>/dev/null", $output, $exitCode);

        return $exitCode === 0 && ! empty($output[0]) ? trim($output[0]) : null;
    }
}
