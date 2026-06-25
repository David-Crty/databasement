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

    public function getMessage(): NotificationMessage
    {
        $count = $this->missingSnapshots->count();

        return $this->message(
            title: '⚠️ '.trans_choice(':count backup file missing|:count backup files missing', $count, ['count' => $count]),
            body: trans_choice(':count backup file could not be found on its storage volume.|:count backup files could not be found on their storage volumes.', $count, ['count' => $count]),
            actionText: '🔗 '.__('View Missing Files'),
            actionUrl: route('snapshots.index', ['fileMissing' => '1']),
            errorLabel: '📁 '.__('Missing Files'),
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
