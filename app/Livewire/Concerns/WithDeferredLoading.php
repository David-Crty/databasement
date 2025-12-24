<?php

namespace App\Livewire\Concerns;

/**
 * Trait for Livewire components that use deferred loading (wire:init).
 *
 * Components using this trait should:
 * 1. Call initDeferredLoading() in mount() if any initialization is needed
 * 2. Implement the loadContent() method to load the actual data
 * 3. Use wire:init="load" in the Blade view
 */
trait WithDeferredLoading
{
    public bool $loaded = false;

    /**
     * Load the component content. This is called by wire:init.
     */
    public function load(): void
    {
        $this->loadContent();
        $this->loaded = true;
    }

    /**
     * Implement this method to load the actual content.
     */
    abstract protected function loadContent(): void;
}
