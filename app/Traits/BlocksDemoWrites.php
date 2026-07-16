<?php

namespace App\Traits;

/**
 * Shares the demo-mode write guard across Livewire components. The demo user
 * may view every form but never persist changes; instead of erroring, write
 * actions flash a friendly read-only notice and abort.
 *
 * Requires the {@see Toast} trait for the warning() helper.
 */
trait BlocksDemoWrites
{
    /**
     * Flash the read-only notice and return true when the current user is the
     * demo user, so the caller can short-circuit its write action:
     *
     *     if ($this->blockedForDemo(route('volumes.index'))) {
     *         return;
     *     }
     */
    protected function blockedForDemo(string $redirectTo): bool
    {
        if (auth()->user()?->isDemo() !== true) {
            return false;
        }

        $this->warning(
            title: __('Demo mode is enabled. Changes cannot be saved.'),
            redirectTo: $redirectTo,
            flashAs: 'demo_notice',
        );

        return true;
    }
}
