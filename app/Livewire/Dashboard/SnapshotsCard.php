<?php

namespace App\Livewire\Dashboard;

use App\Jobs\VerifySnapshotFileJob;
use App\Models\Snapshot;
use App\Services\CurrentOrganization;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

#[Lazy]
class SnapshotsCard extends Component
{
    use Toast;

    public int $totalSnapshots = 0;

    public int $verifiedSnapshots = 0;

    public int $missingSnapshots = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    #[On('refresh-dashboard')]
    public function refreshDashboard(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $baseQuery = Snapshot::forCurrentOrg()->completed();

        $this->totalSnapshots = $baseQuery->count();
        $this->verifiedSnapshots = (clone $baseQuery)->whereNotNull('file_verified_at')->count();
        $this->missingSnapshots = (clone $baseQuery)->fileMissing()->count();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
    }

    public function verifyFiles(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $currentOrg = app(CurrentOrganization::class);
        $lockKey = 'verify-snapshot-files:'.$currentOrg->id();
        $lock = Cache::lock($lockKey, 300);

        if (! $lock->get()) {
            $this->warning(__('File verification is already running.'));

            return;
        }

        VerifySnapshotFileJob::dispatch($currentOrg->id());

        $this->success(__('File verification job dispatched.'));
    }

    public function render(): View
    {
        return view('livewire.dashboard.snapshots-card');
    }
}
