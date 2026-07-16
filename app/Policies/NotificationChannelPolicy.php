<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\NotificationChannel;
use App\Models\User;

/**
 * Authorizes the Configuration → Notification screen. Notification channels are
 * a configuration concern governed by the manage-notifications catalogue
 * ability; the screen is viewable by every org member (read-only), but only
 * holders of the ability (and super admins, via the Gate::before catalogue
 * bypass) may create, edit, delete or test channels.
 *
 * @see NotificationChannel
 */
class NotificationChannelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Create, edit, delete and test notification channels.
     */
    public function manage(User $user): bool
    {
        return $user->can(Ability::ManageNotifications->value);
    }
}
