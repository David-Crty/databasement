<?php

namespace App\Livewire\Dashboard;

use App\Jobs\VerifySnapshotFileJob;
use App\Livewire\Concerns\WithDeferredLoading;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Mary\Traits\Toast;

class StatsCards extends Component
{
    use Toast, WithDeferredLoading;

    public int $totalSnapshots = 0;

    public string $totalStorage = '0 B';

    public float $successRate = 0;

    public int $runningJobs = 0;

    public int $missingSnapshots = 0;

    public int $verifiedSnapshots = 0;

    protected function loadContent(): void
    {
        $this->totalSnapshots = Snapshot::count();

        $totalBytes = Snapshot::sum('file_size');
        $this->totalStorage = Formatters::humanFileSize((int) $totalBytes);

        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $completedJobs = BackupJob::where('created_at', '>=', $thirtyDaysAgo)
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        if ($completedJobs->count() > 0) {
            $successful = $completedJobs->where('status', 'completed')->count();
            $this->successRate = round(($successful / $completedJobs->count()) * 100, 1);
        }

        $this->runningJobs = BackupJob::where('status', 'running')->count();

        $this->verifiedSnapshots = Snapshot::whereNotNull('file_verified_at')->count();
        $this->missingSnapshots = Snapshot::where('file_exists', false)->count();
    }

    public function verifyFiles(): void
    {
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
        return view('livewire.dashboard.stats-cards');
    }
}
