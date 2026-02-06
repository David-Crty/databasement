<?php

namespace App\Notifications;

use Illuminate\Support\Collection;

class SnapshotsMissingNotification extends BaseFailedNotification
{
    private const MAX_LISTED = 10;

    /**
     * @param  Collection<int, array{server: string, database: string, filename: string}>  $missingSnapshots
     */
    public function __construct(
        public Collection $missingSnapshots
    ) {
        parent::__construct(new \RuntimeException($this->fileList()));
    }

    public function getMessage(): FailedNotificationMessage
    {
        $count = $this->missingSnapshots->count();

        return $this->message(
            title: "⚠️ {$count} backup ".str('file')->plural($count).' missing',
            body: "{$count} backup ".str('file')->plural($count).' could not be found on their storage volumes.',
            actionText: '🔗 View Missing Files',
            actionUrl: route('jobs.index', ['fileMissing' => '1']),
            footerText: '🕐 '.now()->toDateTimeString(),
            errorLabel: '📁 Missing Files',
        );
    }

    private function fileList(): string
    {
        $lines = $this->missingSnapshots
            ->take(self::MAX_LISTED)
            ->map(fn (array $snapshot) => "{$snapshot['server']} / {$snapshot['database']} — {$snapshot['filename']}")
            ->toArray();

        if ($this->missingSnapshots->count() > self::MAX_LISTED) {
            $remaining = $this->missingSnapshots->count() - self::MAX_LISTED;
            $lines[] = "... and {$remaining} more";
        }

        return implode("\n", $lines);
    }
}
