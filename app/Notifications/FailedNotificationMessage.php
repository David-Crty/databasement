<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramMessage;

class FailedNotificationMessage
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $errorMessage,
        public string $errorLabel,
        public string $actionText,
        public string $actionUrl,
        public string $footerText,
        public array $fields = [],
    ) {}

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->error()
            ->markdown('mail.failed-notification', [
                'title' => $this->title,
                'body' => $this->body,
                'fields' => $this->fields,
                'errorMessage' => $this->errorMessage,
                'actionText' => $this->actionText,
                'actionUrl' => $this->actionUrl,
                'footerText' => $this->footerText,
            ]);
    }

    public function toSlack(): SlackMessage
    {
        return (new SlackMessage)
            ->username('Databasement')
            ->emoji(':rotating_light:')
            ->text($this->title)
            ->headerBlock($this->title)
            ->contextBlock(fn (ContextBlock $block) => $block->text($this->footerText))
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) {
                $block->text($this->body);
                foreach ($this->fields as $label => $value) {
                    $block->field("*{$label}:*\n{$value}")->markdown();
                }
            })
            ->sectionBlock(fn (SectionBlock $block) => $block->text("*{$this->errorLabel}:*\n```{$this->errorMessage}```")->markdown())
            ->dividerBlock()
            ->sectionBlock(fn (SectionBlock $block) => $block->text("<{$this->actionUrl}|{$this->actionText}>")->markdown());
    }

    public function toDiscord(): DiscordMessage
    {
        $embedFields = [];

        foreach ($this->fields as $label => $value) {
            $embedFields[] = ['name' => $label, 'value' => $value, 'inline' => true];
        }

        $embedFields[] = ['name' => 'Error', 'value' => "```{$this->errorMessage}```", 'inline' => false];
        $embedFields[] = ['name' => 'Job Details', 'value' => "[{$this->actionText}]({$this->actionUrl})", 'inline' => false];

        return DiscordMessage::create()
            ->body($this->body)
            ->embed([
                'title' => $this->title,
                'color' => 15158332, // Red color
                'fields' => $embedFields,
                'footer' => ['text' => $this->footerText],
            ]);
    }

    public function toTelegram(string $chatId): TelegramMessage
    {
        $lines = ['<b>'.e($this->title).'</b>', '', e($this->body), ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = '<b>'.e($label).':</b> '.e($value);
        }

        $lines[] = '';
        $lines[] = '<b>'.e($this->errorLabel).':</b>';
        $lines[] = '<code>'.e($this->errorMessage).'</code>';
        $lines[] = '';
        $lines[] = '<i>'.e($this->footerText).'</i>';

        return TelegramMessage::create(implode("\n", $lines))
            ->to($chatId)
            ->options(['parse_mode' => 'HTML'])
            ->button($this->actionText, $this->actionUrl);
    }

    public function toPushover(): PushoverMessage
    {
        $lines = [$this->body, ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        $lines[] = '';
        $lines[] = "{$this->errorLabel}: {$this->errorMessage}";

        return PushoverMessage::create(implode("\n", $lines))
            ->title($this->title)
            ->highPriority()
            ->url($this->actionUrl, $this->actionText);
    }

    /**
     * @return array{title: string, message: string, priority: int}
     */
    public function toGotify(): array
    {
        $lines = [$this->body, ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        $lines[] = '';
        $lines[] = "{$this->errorLabel}: {$this->errorMessage}";
        $lines[] = '';
        $lines[] = "{$this->actionText}: {$this->actionUrl}";

        return [
            'title' => $this->title,
            'message' => implode("\n", $lines),
            'priority' => 8,
        ];
    }

    /**
     * @return array{event: string, title: string, body: string, fields: array<string, string>, error: string, action_url: string, timestamp: string}
     */
    public function toWebhook(): array
    {
        return [
            'event' => 'notification.failed',
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'error' => $this->errorMessage,
            'action_url' => $this->actionUrl,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
