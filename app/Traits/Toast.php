<?php

namespace App\Traits;

use Blade;
use Livewire\Features\SupportRedirects\Redirector;

trait Toast
{
    public function toast(
        string $type,
        string $title,
        ?string $description = null,
        ?string $position = null,
        string $icon = 'o-information-circle',
        string $css = 'alert-info',
        int $timeout = 6000,
        ?string $redirectTo = null,
        bool $noProgress = false,
        ?string $progressClass = null,
        ?string $flashAs = null,
    ): ?Redirector {
        $toast = [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'position' => $position,
            'icon' => Blade::render("<x-mary-icon class='w-7 h-7' name='".$icon."' />"),
            'css' => $css,
            'timeout' => $timeout,
            'noProgress' => $noProgress,
            'progressClass' => $progressClass,
        ];

        $this->js('toast('.json_encode(['toast' => $toast]).')');

        session()->flash('mary.toast.title', $title);
        session()->flash('mary.toast.description', $description);

        if ($flashAs) {
            session()->flash($flashAs, true);
        }

        if ($redirectTo) {
            return $this->redirect($redirectTo, navigate: true);
        }

        return null;
    }

    public function success(
        string $title,
        ?string $description = null,
        ?string $position = 'toast-bottom',
        string $icon = 'o-check-circle',
        string $css = 'alert-success',
        int $timeout = 6000,
        ?string $redirectTo = null,
        bool $noProgress = false,
        ?string $progressClass = null,
        ?string $flashAs = null,
    ): ?Redirector {
        return $this->toast('success', $title, $description, $position, $icon, $css, $timeout, $redirectTo, $noProgress, $progressClass, $flashAs);
    }

    public function warning(
        string $title,
        ?string $description = null,
        ?string $position = 'toast-bottom',
        string $icon = 'o-exclamation-triangle',
        string $css = 'alert-warning',
        int $timeout = 9000,
        ?string $redirectTo = null,
        bool $noProgress = false,
        ?string $progressClass = null,
        ?string $flashAs = null,
    ): ?Redirector {
        return $this->toast('warning', $title, $description, $position, $icon, $css, $timeout, $redirectTo, $noProgress, $progressClass, $flashAs);
    }

    public function error(
        string $title,
        ?string $description = null,
        ?string $position = 'toast-bottom',
        string $icon = 'o-x-circle',
        string $css = 'alert-error',
        int $timeout = 9000,
        ?string $redirectTo = null,
        bool $noProgress = false,
        ?string $progressClass = null,
        ?string $flashAs = null,
    ): ?Redirector {
        return $this->toast('error', $title, $description, $position, $icon, $css, $timeout, $redirectTo, $noProgress, $progressClass, $flashAs);
    }

    public function info(
        string $title,
        ?string $description = null,
        ?string $position = 'toast-bottom',
        string $icon = 'o-information-circle',
        string $css = 'alert-info',
        int $timeout = 6000,
        ?string $redirectTo = null,
        bool $noProgress = false,
        ?string $progressClass = null,
        ?string $flashAs = null,
    ): ?Redirector {
        return $this->toast('info', $title, $description, $position, $icon, $css, $timeout, $redirectTo, $noProgress, $progressClass, $flashAs);
    }
}
