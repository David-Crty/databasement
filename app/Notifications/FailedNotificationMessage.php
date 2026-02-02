<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;

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
}
