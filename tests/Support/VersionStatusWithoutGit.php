<?php

namespace Tests\Support;

use App\Livewire\VersionStatus;

class VersionStatusWithoutGit extends VersionStatus
{
    protected function getGitShortHash(): ?string
    {
        return null;
    }
}
