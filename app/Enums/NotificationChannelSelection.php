<?php

namespace App\Enums;

enum NotificationChannelSelection: string
{
    case All = 'all';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All channels'),
            self::Selected => __('Selected channels'),
        };
    }
}
