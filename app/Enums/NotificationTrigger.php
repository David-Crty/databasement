<?php

namespace App\Enums;

enum NotificationTrigger: string
{
    case All = 'all';
    case Success = 'success';
    case Failure = 'failure';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All events'),
            self::Success => __('Success only'),
            self::Failure => __('Failure only'),
            self::None => __('None'),
        };
    }

    public function shouldNotifyOn(string $event): bool
    {
        return match ($this) {
            self::All => true,
            self::Failure => $event === 'failure',
            self::Success => $event === 'success',
            self::None => false,
        };
    }
}
