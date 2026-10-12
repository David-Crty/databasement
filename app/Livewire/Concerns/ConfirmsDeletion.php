<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * The confirm-then-delete modal of a page listing records. The consuming
 * component uses {@see \Illuminate\Foundation\Auth\Access\AuthorizesRequests},
 * and both steps check the record's `delete` policy.
 */
trait ConfirmsDeletion
{
    #[Locked]
    public int|string|null $deleteId = null;

    public bool $showDeleteModal = false;

    /**
     * Open the modal for a record the user may delete.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    protected function confirmDeletion(Builder $query, int|string $id): Model
    {
        $record = $query->findOrFail($id);

        $this->authorize('delete', $record);

        $this->deleteId = $id;
        $this->showDeleteModal = true;

        return $record;
    }

    /**
     * The record the modal was opened for, checked again, or null when none.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    protected function pendingDeletion(Builder $query): ?Model
    {
        if ($this->deleteId === null) {
            return null;
        }

        $record = $query->findOrFail($this->deleteId);

        $this->authorize('delete', $record);

        return $record;
    }

    protected function closeDeletion(): void
    {
        $this->deleteId = null;
        $this->showDeleteModal = false;
    }
}
