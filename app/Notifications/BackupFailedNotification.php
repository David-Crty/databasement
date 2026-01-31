<?php

namespace App\Notifications;

use App\Models\Snapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class BackupFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Snapshot $snapshot,
        public \Throwable $exception
    ) {
        $this->onQueue('backups');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->routes['mail'] ?? null) {
            $channels[] = 'mail';
        }

        if ($notifiable->routes['slack'] ?? null) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serverName = $this->snapshot->databaseServer->name ?? 'Unknown';

        return (new MailMessage)
            ->subject('Backup Failed: '.$serverName)
            ->error()
            ->markdown('mail.backup-failed', [
                'serverName' => $serverName,
                'databaseName' => $this->snapshot->database_name ?? 'Unknown',
                'errorMessage' => $this->exception->getMessage(),
                'timestamp' => now()->toDateTimeString(),
                'jobUrl' => url('/backup-jobs?job='.$this->snapshot->backup_job_id),
            ]);
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $serverName = $this->snapshot->databaseServer->name ?? 'Unknown';
        $databaseName = $this->snapshot->database_name ?? 'Unknown';
        $errorMessage = $this->exception->getMessage();
        $timestamp = now()->toDateTimeString();
        $jobUrl = url('/backup-jobs?job='.$this->snapshot->backup_job_id);

        return (new SlackMessage)
            ->text("Backup failed for {$serverName}")
            ->headerBlock('Backup Failed')
            ->contextBlock(function (ContextBlock $block) use ($timestamp) {
                $block->text($timestamp);
            })
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) use ($serverName, $databaseName) {
                $block->text('A backup job has failed and requires your attention.');
                $block->field("*Server:*\n{$serverName}")->markdown();
                $block->field("*Database:*\n{$databaseName}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($errorMessage) {
                $block->text("*Error Details:*\n```{$errorMessage}```")->markdown();
            })
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) use ($jobUrl) {
                $block->text("<{$jobUrl}|View Job Details>")->markdown();
            });
    }
}
