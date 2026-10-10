<?php

namespace App\Livewire\DatabaseServer;

use App\Livewire\DatabaseServer\Concerns\InteractsWithServerForm;
use App\Models\DatabaseServer;
use App\Traits\BlocksDemoWrites;
use App\Traits\SurfacesValidationErrors;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Edit Database Server')]
class Edit extends Component
{
    use AuthorizesRequests, BlocksDemoWrites, InteractsWithServerForm, SurfacesValidationErrors, Toast;

    public Form $form;

    #[Locked]
    public string $returnUrl = '';

    public function mount(DatabaseServer $server): void
    {
        $this->authorize('viewForm', $server);

        $this->form->setServer($server);

        $this->returnUrl = $this->safeReturnUrl(url()->previous(route('database-servers.index')));
    }

    public function save(): void
    {
        if ($this->blockedForDemo($this->returnUrl)) {
            return;
        }

        $this->authorize('update', $this->form->server);

        if ($this->withValidationFeedback(fn (): bool => $this->form->update())) {
            $this->success(
                title: __('Database server updated successfully!'),
                redirectTo: $this->returnUrl,
            );
        }
    }

    private function safeReturnUrl(string $url): string
    {
        $fallback = route('database-servers.index');
        $appRoot = request()->getSchemeAndHttpHost();

        // Same-origin only (scheme + host + port).
        if (! str_starts_with($url, $appRoot.'/') && $url !== $appRoot) {
            return $fallback;
        }

        // Don't redirect back to the edit page itself (strip query string/fragment before comparing).
        if (strtok($url, '?#') === route('database-servers.edit', $this->form->server)) {
            return $fallback;
        }

        return $url;
    }

    public function loadDatabases(): void
    {
        if (! $this->form->isSqlite() && ! $this->form->isRedis() && ! $this->form->hasAgent()) {
            $this->form->loadAvailableDatabases();
        }
    }

    public function render(): View
    {
        return view('livewire.database-server.edit');
    }
}
