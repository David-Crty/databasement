<?php

namespace App\Notifications;

use App\Models\Restore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class RestoreFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Restore $restore,
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
        $targetServerName = $this->restore->targetServer->name ?? 'Unknown';

        return (new MailMessage)
            ->subject('Restore Failed: '.$targetServerName)
            ->error()
            ->markdown('mail.restore-failed', [
                'targetServerName' => $targetServerName,
                'schemaName' => $this->restore->schema_name ?? 'Unknown',
                'snapshotFilename' => $this->restore->snapshot->filename ?? 'Unknown',
                'errorMessage' => $this->exception->getMessage(),
                'timestamp' => now()->toDateTimeString(),
                'jobUrl' => url('/backup-jobs?job='.$this->restore->backup_job_id),
            ]);
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $targetServerName = $this->restore->targetServer->name ?? 'Unknown';
        $schemaName = $this->restore->schema_name ?? 'Unknown';
        $snapshotFilename = $this->restore->snapshot->filename ?? 'Unknown';
        $errorMessage = $this->exception->getMessage();
        $timestamp = now()->toDateTimeString();
        $jobUrl = url('/backup-jobs?job='.$this->restore->backup_job_id);

        return (new SlackMessage)
            ->text("Restore failed for {$targetServerName}")
            ->headerBlock('Restore Failed')
            ->contextBlock(function (ContextBlock $block) use ($timestamp) {
                $block->text($timestamp);
            })
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) use ($targetServerName, $schemaName) {
                $block->text('A restore job has failed and requires your attention.');
                $block->field("*Target Server:*\n{$targetServerName}")->markdown();
                $block->field("*Target Database:*\n{$schemaName}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($snapshotFilename) {
                $block->field("*Source Snapshot:*\n{$snapshotFilename}")->markdown();
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
