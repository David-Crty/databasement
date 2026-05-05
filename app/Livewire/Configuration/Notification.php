<?php

namespace App\Livewire\Configuration;

use App\Enums\NotificationChannelType;
use App\Livewire\Forms\NotificationChannelForm;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

#[Title('Configuration')]
class Notification extends Component
{
    use Toast;

    public NotificationChannelForm $channelForm;

    // Notification channel modal state
    public bool $showChannelModal = false;

    public ?string $editingChannelId = null;

    public ?string $deleteChannelId = null;

    public bool $showDeleteChannelModal = false;

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()->isAdmin();
    }

    public function openChannelModal(?string $channelId = null): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

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
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

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
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

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
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

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
        return array_map(
            fn (NotificationChannelType $type) => ['id' => $type->value, 'name' => $type->label()],
            NotificationChannelType::cases(),
        );
    }

    public function render(): View
    {
        return view('livewire.configuration.notification', [
            'channelTypeOptions' => $this->getChannelTypeOptions(),
            'notificationChannels' => $this->notificationChannels(),
        ]);
    }
}
