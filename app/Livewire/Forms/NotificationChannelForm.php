<?php

namespace App\Livewire\Forms;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use Illuminate\Validation\Rule;
use Livewire\Form;

class NotificationChannelForm extends Form
{
    public ?NotificationChannel $channel = null;

    public string $name = '';

    public string $type = 'email';

    // Config fields (flat, type-conditional)
    public string $config_to = '';

    public string $config_webhook_url = '';

    public string $config_token = '';

    public string $config_channel_id = '';

    public string $config_bot_token = '';

    public string $config_chat_id = '';

    public string $config_user_key = '';

    public string $config_url = '';

    public string $config_secret = '';

    // Sensitive field existence flags
    public bool $has_config_webhook_url = false;

    public bool $has_config_token = false;

    public bool $has_config_bot_token = false;

    public bool $has_config_user_key = false;

    public bool $has_config_url = false;

    public bool $has_config_secret = false;

    public function setChannel(NotificationChannel $channel): void
    {
        $this->channel = $channel;
        $this->name = $channel->name;
        $this->type = $channel->type->value;

        $config = $channel->config;

        // Load non-sensitive fields directly, set existence flags for sensitive ones
        match ($channel->type) {
            NotificationChannelType::Email => $this->config_to = $config['to'] ?? '',
            NotificationChannelType::Slack => $this->has_config_webhook_url = ! empty($config['webhook_url']),
            NotificationChannelType::DiscordWebhook => $this->has_config_url = ! empty($config['url']),
            NotificationChannelType::Discord => $this->loadDiscordConfig($config),
            NotificationChannelType::Telegram => $this->loadTelegramConfig($config),
            NotificationChannelType::Pushover => $this->loadPushoverConfig($config),
            NotificationChannelType::Gotify => $this->loadGotifyConfig($config),
            NotificationChannelType::Webhook => $this->loadWebhookConfig($config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function loadDiscordConfig(array $config): void
    {
        $this->has_config_token = ! empty($config['token']);
        $this->config_channel_id = $config['channel_id'] ?? '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function loadTelegramConfig(array $config): void
    {
        $this->has_config_bot_token = ! empty($config['bot_token']);
        $this->config_chat_id = $config['chat_id'] ?? '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function loadPushoverConfig(array $config): void
    {
        $this->has_config_token = ! empty($config['token']);
        $this->has_config_user_key = ! empty($config['user_key']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function loadGotifyConfig(array $config): void
    {
        $this->config_url = $config['url'] ?? '';
        $this->has_config_token = ! empty($config['token']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function loadWebhookConfig(array $config): void
    {
        $this->config_url = $config['url'] ?? '';
        $this->has_config_secret = ! empty($config['secret']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = NotificationChannelType::tryFrom($this->type);
        $isEdit = $this->channel !== null;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_column(NotificationChannelType::cases(), 'value'))],
        ];

        return match ($type) {
            NotificationChannelType::Email => array_merge($rules, [
                'config_to' => ['required', 'email', 'max:255'],
            ]),
            NotificationChannelType::Slack => array_merge($rules, [
                'config_webhook_url' => [($isEdit && $this->has_config_webhook_url) ? 'nullable' : 'required', 'string', 'url', 'max:500'],
            ]),
            NotificationChannelType::Discord => array_merge($rules, [
                'config_token' => [($isEdit && $this->has_config_token) ? 'nullable' : 'required', 'string', 'max:500'],
                'config_channel_id' => ['required', 'string', 'max:100'],
            ]),
            NotificationChannelType::DiscordWebhook => array_merge($rules, [
                'config_url' => [($isEdit && $this->has_config_url) ? 'nullable' : 'required', 'string', 'url', 'max:500'],
            ]),
            NotificationChannelType::Telegram => array_merge($rules, [
                'config_bot_token' => [($isEdit && $this->has_config_bot_token) ? 'nullable' : 'required', 'string', 'max:500'],
                'config_chat_id' => ['required', 'string', 'max:100'],
            ]),
            NotificationChannelType::Pushover => array_merge($rules, [
                'config_token' => [($isEdit && $this->has_config_token) ? 'nullable' : 'required', 'string', 'max:500'],
                'config_user_key' => [($isEdit && $this->has_config_user_key) ? 'nullable' : 'required', 'string', 'max:500'],
            ]),
            NotificationChannelType::Gotify => array_merge($rules, [
                'config_url' => ['required', 'string', 'url', 'max:500'],
                'config_token' => [($isEdit && $this->has_config_token) ? 'nullable' : 'required', 'string', 'max:500'],
            ]),
            NotificationChannelType::Webhook => array_merge($rules, [
                'config_url' => ['required', 'string', 'url', 'max:500'],
                'config_secret' => ['nullable', 'string', 'max:500'],
            ]),
            default => $rules,
        };
    }

    /**
     * Build the config array from form fields.
     *
     * @return array<string, mixed>
     */
    public function buildConfig(): array
    {
        $type = NotificationChannelType::from($this->type);

        $config = match ($type) {
            NotificationChannelType::Email => ['to' => $this->config_to],
            NotificationChannelType::Slack => ['webhook_url' => $this->config_webhook_url],
            NotificationChannelType::Discord => ['token' => $this->config_token, 'channel_id' => $this->config_channel_id],
            NotificationChannelType::DiscordWebhook => ['url' => $this->config_url],
            NotificationChannelType::Telegram => ['bot_token' => $this->config_bot_token, 'chat_id' => $this->config_chat_id],
            NotificationChannelType::Pushover => ['token' => $this->config_token, 'user_key' => $this->config_user_key],
            NotificationChannelType::Gotify => ['url' => $this->config_url, 'token' => $this->config_token],
            NotificationChannelType::Webhook => ['url' => $this->config_url, 'secret' => $this->config_secret],
        };

        $decryptedConfig = $this->channel?->getDecryptedConfig() ?? [];
        $rawConfig = $this->channel?->config ?? []; // @phpstan-ignore nullsafe.neverNull

        return $type->encryptSensitiveFields(
            $type->mergeSensitiveFromPersisted($config, $decryptedConfig),
            $rawConfig,
        );
    }

    public function store(): NotificationChannel
    {
        $this->validate();

        return NotificationChannel::create([
            'name' => $this->name,
            'type' => $this->type,
            'config' => $this->buildConfig(),
        ]);
    }

    public function update(): void
    {
        // Force persisted type to prevent client-side tampering
        $this->type = $this->channel->type->value;

        $this->validate();

        $this->channel->update([
            'name' => $this->name,
            'config' => $this->buildConfig(),
        ]);
    }

    public function resetFields(): void
    {
        $this->reset();
        $this->resetValidation();
    }
}
