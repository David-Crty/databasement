<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithDeferredLoading;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

class StatsCards extends Component
{
    use WithDeferredLoading;

    public int $totalSnapshots = 0;

    public string $totalStorage = '0 B';

    public float $successRate = 0;

    public int $runningJobs = 0;

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
    }

    public function render(): View
    {
        return view('livewire.dashboard.stats-cards');
    }
}
