<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\Forms\DatabaseServerForm;
use App\Models\DatabaseServer;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Database Server')]
class Edit extends Component
{
    use AuthorizesRequests, Toast;

    public DatabaseServerForm $form;

    public function mount(DatabaseServer $server): void
    {
        $this->authorize('viewForm', $server);

        $this->form->setServer($server);
    }

    public function save(): void
    {
        if (Gate::denies('update', $this->form->server)) {
            $this->warning(
                title: __('Demo mode is enabled. Changes cannot be saved.'),
                redirectTo: $this->safeRedirectUrl(),
                flashAs: 'demo_notice',
            );

            return;
        }

        if ($this->form->update()) {
            $this->success(
                title: __('Database server updated successfully!'),
                redirectTo: $this->safeRedirectUrl(),
            );
        }
    }

    private function safeRedirectUrl(): string
    {
        $fallback = route('database-servers.index');
        $previous = url()->previous($fallback);

        // Reject external URLs to prevent open redirect.
        if (parse_url($previous, PHP_URL_HOST) !== request()->getHost()) {
            return $fallback;
        }

        // Don't redirect back to the edit page itself.
        if ($previous === route('database-servers.edit', $this->form->server)) {
            return $fallback;
        }

        return $previous;
    }

    public function addBackup(?string $defaultScheduleId = null): void
    {
        $this->form->addBackup($defaultScheduleId);
    }

    public function removeBackup(int $index): void
    {
        $this->form->removeBackup($index);
    }

    public function addDatabasePath(int $backupIndex): void
    {
        $this->form->addDatabasePath($backupIndex);
    }

    public function removeDatabasePath(int $backupIndex, int $pathIndex): void
    {
        $this->form->removeDatabasePath($backupIndex, $pathIndex);
    }

    public function testConnection(): void
    {
        $this->form->testConnection();
    }

    public function testSshConnection(): void
    {
        $this->form->testSshConnection();
    }

    public function generateSshKey(): void
    {
        $this->form->generateSshKey();
    }

    public function refreshVolumes(): void
    {
        $this->success(__('Volume list refreshed.'));
    }

    public function refreshSchedules(): void
    {
        $this->success(__('Schedule list refreshed.'));
    }

    public function loadDatabases(): void
    {
        if (! $this->form->isSqlite() && ! $this->form->isRedis() && ! $this->form->hasAgent()) {
            $this->form->loadAvailableDatabases();
        }
    }

    public function toggleNotificationChannel(string $channelId): void
    {
        $this->form->toggleNotificationChannel($channelId);
    }

    public function render(): View
    {
        return view('livewire.database-server.edit');
    }
}
