<?php

namespace App\Livewire\Volume\Connectors;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

abstract class BaseConfig extends Component
{
    /** @var array<string, mixed> */
    #[Modelable]
    public array $config = [];

    public bool $readonly = false;

    public bool $isEditing = false;

    public function mount(): void
    {
        $this->config = array_merge(static::defaultConfig(), $this->config);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public static function defaultConfig(): array;

    /**
     * @return array<string, mixed>
     */
    abstract public static function rules(string $prefix): array;

    /**
     * @return view-string
     */
    abstract protected function viewName(): string;

    public function render(): View
    {
        /** @var view-string $viewName */
        $viewName = $this->viewName();

        return view($viewName);
    }
}
