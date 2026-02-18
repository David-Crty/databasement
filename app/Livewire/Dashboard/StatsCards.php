<?php

namespace App\Livewire\Dashboard;

use App\Jobs\VerifySnapshotFileJob;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Mary\Traits\Toast;

#[Lazy]
class StatsCards extends Component
{
    use Toast;

    public int $totalSnapshots = 0;

    public string $totalStorage = '0 B';

    public float $successRate = 0;

    public int $runningJobs = 0;

    public int $missingSnapshots = 0;

    public int $verifiedSnapshots = 0;

    public function mount(): void
    {
        $successfulSnapshots = Snapshot::whereRelation('job', 'status', 'completed');

        $this->totalSnapshots = $successfulSnapshots->count();

        $totalBytes = $successfulSnapshots->sum('file_size');
        $this->totalStorage = Formatters::humanFileSize((int) $totalBytes);

        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $total = BackupJob::where('created_at', '>=', $thirtyDaysAgo)
            ->whereIn('status', ['completed', 'failed'])
            ->count();

        if ($total > 0) {
            $successful = BackupJob::where('created_at', '>=', $thirtyDaysAgo)
                ->where('status', 'completed')
                ->count();
            $this->successRate = round(($successful / $total) * 100, 1);
        }

        $this->runningJobs = BackupJob::where('status', 'running')->count();

        $this->verifiedSnapshots = Snapshot::whereRelation('job', 'status', 'completed')
            ->whereNotNull('file_verified_at')->count();
        $this->missingSnapshots = Snapshot::whereRelation('job', 'status', 'completed')
            ->where('file_exists', false)->count();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder-stats');
    }

    /** @return array{bg: string, text: string} */
    #[Computed]
    public function successRateColor(): array
    {
        return match (true) {
            $this->successRate >= 90 => ['bg' => 'bg-success/10', 'text' => 'text-success'],
            $this->successRate >= 70 => ['bg' => 'bg-warning/10', 'text' => 'text-warning'],
            default => ['bg' => 'bg-error/10', 'text' => 'text-error'],
        };
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
