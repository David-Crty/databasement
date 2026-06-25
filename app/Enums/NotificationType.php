<?php

namespace App\Enums;

enum NotificationType: string
{
    case Success = 'success';
    case Failure = 'failure';

    public function slackEmoji(): string
    {
        return match ($this) {
            self::Success => ':white_check_mark:',
            self::Failure => ':rotating_light:',
        };
    }

    public function discordColor(): int
    {
        return match ($this) {
            self::Success => 3066993, // Green
            self::Failure => 15158332, // Red
        };
    }

    public function gotifyPriority(): int
    {
        return match ($this) {
            self::Success => 4,
            self::Failure => 8,
        };
    }

    public function mailButtonColor(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failure => 'primary',
        };
    }
}
