<?php

namespace App\Livewire\Dashboard;

use App\Jobs\VerifySnapshotFileJob;
use App\Models\Snapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Mary\Traits\Toast;
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
        $baseQuery = Snapshot::whereRelation('job', 'status', 'completed');

        $this->totalSnapshots = $baseQuery->count();
        $this->verifiedSnapshots = (clone $baseQuery)->whereNotNull('file_verified_at')->count();
        $this->missingSnapshots = (clone $baseQuery)->where('file_exists', false)->count();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
    }

    public function verifyFiles(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $lock = Cache::lock('verify-snapshot-files', 300);

        if (! $lock->get()) {
            $this->warning(__('File verification is already running.'), position: 'toast-bottom');

            return;
        }

        VerifySnapshotFileJob::dispatch();

        $this->success(__('File verification job dispatched.'), position: 'toast-bottom');
    }

    public function render(): View
    {
        return view('livewire.dashboard.snapshots-card');
    }
}
