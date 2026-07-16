<?php

namespace App\Livewire\Configuration;

use App\Livewire\Forms\ConfigurationForm;
use App\Models\DatabaseServer;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Displays the environment variables that control application behavior
 * (read-only) plus the one runtime-configurable application setting: the global
 * Adminer toggle, which only super admins may change.
 */
#[Title('Configuration')]
class Application extends Component
{
    use Toast;

    public ConfigurationForm $form;

    public function mount(): void
    {
        $this->form->loadFromConfig();
    }

    /**
     * The Adminer toggle is a global feature switch, governed by
     * DatabaseServerPolicy@manageAdminer (super admins). The rest of the screen
     * is read-only for everyone.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('manageAdminer', DatabaseServer::class);
    }

    public function saveApplicationConfig(): void
    {
        abort_unless(auth()->user()->can('manageAdminer', DatabaseServer::class), Response::HTTP_FORBIDDEN);

        $this->form->saveApplication();

        $this->success(__('Application configuration saved.'));
    }

    /**
     * @return array<int, array{key: string, label: string, class?: string}>
     */
    public function getHeaders(): array
    {
        return [
            ['key' => 'env', 'label' => __('Environment Variable'), 'class' => 'w-56'],
            ['key' => 'value', 'label' => __('Value'), 'class' => 'w-64'],
            ['key' => 'description', 'label' => __('Description')],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getAppConfig(): array
    {
        return [
            [
                'env' => 'APP_DEBUG',
                'value' => config('app.debug') ? 'true' : 'false',
                'description' => __('Enable debug mode. Should be false in production.'),
            ],
            [
                'env' => 'APP_DISPLAY_TIMEZONE',
                'value' => config('app.display_timezone') ?: '-',
                'description' => __('Timezone used for rendering datetimes in the UI and interpreting scheduled task cron expressions. Storage stays in UTC.'),
            ],
            [
                'env' => 'TRUSTED_PROXIES',
                'value' => config('app.trusted_proxies') ?: '-',
                'description' => __('IP addresses or CIDR ranges of trusted reverse proxies. Use "*" to trust all.'),
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.configuration.application', [
            'headers' => $this->getHeaders(),
            'appConfig' => $this->getAppConfig(),
        ]);
    }
}
