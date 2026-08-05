<?php

namespace App\Livewire\Concerns;

/**
 * Shared filter plumbing for paginated index pages: changing a filter returns
 * to page one, and "clear" resets every filter at once.
 *
 * The consuming component must:
 *
 * - use {@see \Livewire\WithPagination} (for `resetPage()`)
 * - use {@see \App\Traits\Toast} (for the confirmation in `clear()`)
 * - list its own filter properties in `filterProperties()`
 */
trait FiltersAndPaginates
{
    /**
     * The `#[Url]` filter properties this page paginates over.
     *
     * @return list<string>
     */
    abstract protected function filterProperties(): array;

    /**
     * Only filter changes reset the page. Other public state (modal flags,
     * selected ids) leaves the current page alone.
     *
     * @param  string|array<string, mixed>  $property
     */
    public function updated(string|array $property): void
    {
        if (is_string($property) && in_array($property, $this->filterProperties(), true)) {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset($this->filterProperties());
        $this->resetPage();
        $this->success(__('Filters cleared.'));
    }
}
