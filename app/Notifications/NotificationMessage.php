<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramMessage;

class NotificationMessage
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public NotificationType $type,
        public string $title,
        public string $body,
        public string $actionText,
        public string $actionUrl,
        public string $footerText,
        public array $fields = [],
        public ?string $errorMessage = null,
        public ?string $errorLabel = null,
    ) {}

    public function hasError(): bool
    {
        return $this->errorMessage !== null;
    }

    public function toMail(): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title);

        $mail = match ($this->type) {
            NotificationType::Success => $mail->success(),
            NotificationType::Failure => $mail->error(),
        };

        return $mail->markdown('mail.notification', [
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'errorMessage' => $this->errorMessage,
            'errorLabel' => $this->errorLabel,
            'actionText' => $this->actionText,
            'actionUrl' => $this->actionUrl,
            'footerText' => $this->footerText,
            'buttonColor' => $this->type->mailButtonColor(),
            'actionRequired' => $this->type === NotificationType::Failure,
        ]);
    }

    public function toSlack(): SlackMessage
    {
        $message = (new SlackMessage)
            ->username('Databasement')
            ->emoji($this->type->slackEmoji())
            ->text($this->title)
            ->headerBlock($this->title)
            ->contextBlock(fn (ContextBlock $block) => $block->text($this->footerText))
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) {
                $block->text($this->body);
                foreach ($this->fields as $label => $value) {
                    $block->field("*{$label}:*\n{$value}")->markdown();
                }
            });

        if ($this->hasError()) {
            $message->sectionBlock(fn (SectionBlock $block) => $block->text("*{$this->errorLabel}:*\n```{$this->errorMessage}```")->markdown());
        }

        return $message
            ->dividerBlock()
            ->sectionBlock(fn (SectionBlock $block) => $block->text("<{$this->actionUrl}|{$this->actionText}>")->markdown());
    }

    public function toDiscord(): DiscordMessage
    {
        return DiscordMessage::create()
            ->body($this->body)
            ->embed([
                'title' => $this->title,
                'color' => $this->type->discordColor(),
                'fields' => $this->buildEmbedFields(),
                'footer' => ['text' => $this->footerText],
            ]);
    }

    public function toTelegram(string $chatId, ?string $topicId = null): TelegramMessage
    {
        $lines = ['<b>'.e($this->title).'</b>', '', e($this->body), ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = '<b>'.e($label).':</b> '.e($value);
        }

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = '<b>'.e($this->errorLabel).':</b>';
            $lines[] = '<code>'.e($this->errorMessage).'</code>';
        }

        $lines[] = '';
        $lines[] = '<i>'.e($this->footerText).'</i>';

        $options = ['parse_mode' => 'HTML'];

        if ($topicId !== null && $topicId !== '') {
            $options['message_thread_id'] = (int) $topicId;
        }

        return TelegramMessage::create(implode("\n", $lines))
            ->to($chatId)
            ->options($options)
            ->button($this->actionText, $this->actionUrl);
    }

    public function toPushover(): PushoverMessage
    {
        $lines = [$this->body, ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = "{$this->errorLabel}: {$this->errorMessage}";
        }

        $message = PushoverMessage::create(implode("\n", $lines))
            ->title($this->title)
            ->url($this->actionUrl, $this->actionText);

        return match ($this->type) {
            NotificationType::Success => $message->normalPriority(),
            NotificationType::Failure => $message->highPriority(),
        };
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

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = "{$this->errorLabel}: {$this->errorMessage}";
        }

        $lines[] = '';
        $lines[] = "{$this->actionText}: {$this->actionUrl}";

        return [
            'title' => $this->title,
            'message' => implode("\n", $lines),
            'priority' => $this->type->gotifyPriority(),
        ];
    }

    /**
     * @return array{content: string, embeds: array<int, array<string, mixed>>}
     */
    public function toDiscordWebhook(): array
    {
        return [
            'content' => $this->body,
            'embeds' => [
                [
                    'title' => $this->title,
                    'color' => $this->type->discordColor(),
                    'fields' => $this->buildEmbedFields(),
                    'footer' => ['text' => $this->footerText],
                ],
            ],
        ];
    }

    /**
     * @return array{event: string, title: string, body: string, fields: array<string, string>, error?: string, action_url: string, timestamp: string}
     */
    public function toWebhook(string $event): array
    {
        $payload = [
            'event' => $event,
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'action_url' => $this->actionUrl,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->hasError()) {
            $payload['error'] = $this->errorMessage;
        }

        return $payload;
    }

    /**
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function buildEmbedFields(): array
    {
        $embedFields = [];

        foreach ($this->fields as $label => $value) {
            $embedFields[] = ['name' => $label, 'value' => $value, 'inline' => true];
        }

        if ($this->hasError()) {
            $embedFields[] = ['name' => $this->errorLabel, 'value' => "```{$this->errorMessage}```", 'inline' => false];
        }

        $embedFields[] = ['name' => 'Job Details', 'value' => "[{$this->actionText}]({$this->actionUrl})", 'inline' => false];

        return $embedFields;
    }
}
