<?php

namespace App\Livewire\Configuration;

use App\Enums\NotificationChannelType;
use App\Livewire\Configuration\Concerns\GatesConfigurationWrites;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuration')]
class Notification extends Component
{
    use GatesConfigurationWrites, Toast;

    public NotificationChannelForm $channelForm;

    // Notification channel modal state
    public bool $showChannelModal = false;

    public ?string $editingChannelId = null;

    public ?string $deleteChannelId = null;

    public bool $showDeleteChannelModal = false;

    protected function manageGate(): array
    {
        return ['manage', NotificationChannel::class];
    }

    public function openChannelModal(?string $channelId = null): void
    {
        $this->authorizeManage();

        $this->channelForm->resetFields();
        $this->editingChannelId = $channelId;

        if ($channelId) {
            $channel = NotificationChannel::findOrFail($channelId);
            $this->channelForm->setChannel($channel);
        }

        $this->showChannelModal = true;
    }

    public function saveChannel(): void
    {
        $this->authorizeManage();

        if ($this->editingChannelId) {
            $this->channelForm->channel = NotificationChannel::findOrFail($this->editingChannelId);
            $this->channelForm->update();
        } else {
            $this->channelForm->store();
        }

        $this->showChannelModal = false;
        $this->editingChannelId = null;
        $this->channelForm->resetFields();

        $this->success(__('Notification channel saved.'));
    }

    public function confirmDeleteChannel(string $channelId): void
    {
        $this->deleteChannelId = $channelId;
        $this->showDeleteChannelModal = true;
    }

    public function deleteChannel(): void
    {
        $this->authorizeManage();

        if (! $this->deleteChannelId) {
            return;
        }

        NotificationChannel::findOrFail($this->deleteChannelId)->delete();
        $this->showDeleteChannelModal = false;
        $this->deleteChannelId = null;

        $this->success(__('Notification channel deleted.'));
    }

    public function sendTestNotification(string $channelId): void
    {
        $this->authorizeManage();

        $channel = NotificationChannel::findOrFail($channelId);

        try {
            app(NotificationService::class)->sendTestNotification($channel);

            $this->success(__('Test notification sent to: :channel', ['channel' => $channel->name]));
        } catch (\Throwable $e) {
            $this->error(
                title: __('Failed to send test notification: :message', ['message' => $e->getMessage()]),
                timeout: 0
            );
        }
    }

    // --- Computed Properties ---

    /**
     * @return Collection<int, NotificationChannel>
     */
    #[Computed]
    public function notificationChannels(): Collection
    {
        return NotificationChannel::orderBy('name')->get();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getChannelTypeOptions(): array
    {
        return NotificationChannelType::toSelectOptions();
    }

    public function render(): View
    {
        return view('livewire.configuration.notification', [
            'channelTypeOptions' => $this->getChannelTypeOptions(),
            'notificationChannels' => $this->notificationChannels(),
            'headers' => [
                ['key' => 'name', 'label' => __('Name')],
                ['key' => 'type', 'label' => __('Type')],
                ['key' => 'config', 'label' => __('Configuration')],
                ['key' => 'actions', 'label' => '', 'class' => 'w-32 whitespace-nowrap'],
            ],
        ]);
    }
}
