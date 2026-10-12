<?php

namespace App\Livewire\DatabaseServer\Concerns;

use App\Livewire\DatabaseServer\Form;

/**
 * The actions the database server form's view calls, shared by the create
 * and edit pages that host it.
 *
 * @property Form $form
 */
trait InteractsWithServerForm
{
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
        $this->withValidationFeedback(fn () => $this->form->testConnection());
    }

    public function testSshConnection(): void
    {
        $this->withValidationFeedback(fn () => $this->form->testSshConnection());
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

    public function toggleNotificationChannel(string $channelId): void
    {
        $this->form->toggleNotificationChannel($channelId);
    }
}
