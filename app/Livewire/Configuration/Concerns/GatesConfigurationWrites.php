<?php

namespace App\Livewire\Configuration\Concerns;

use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\Response;

/**
 * A configuration screen every member may view, whose changes one policy
 * method governs: the view reads `canManage` to render read-only, and each
 * write action calls {@see self::authorizeManage()}.
 */
trait GatesConfigurationWrites
{
    /**
     * The policy method and model class governing this screen's changes.
     *
     * @return array{string, class-string}
     */
    abstract protected function manageGate(): array;

    #[Computed]
    public function canManage(): bool
    {
        [$ability, $model] = $this->manageGate();

        return auth()->user()->can($ability, $model);
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->canManage(), Response::HTTP_FORBIDDEN);
    }
}
