<?php

namespace App\Livewire\Dashboard;

use App\Enums\BackupJobStatus;
use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class SuccessRateCard extends Component
{
    public float $successRate = 0;

    public int $runningJobs = 0;

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
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $counts = BackupJob::forCurrentOrg()
            ->toBase()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereIn('status', [BackupJobStatus::Completed, BackupJobStatus::Failed])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completed = (int) ($counts[BackupJobStatus::Completed->value] ?? 0);
        $failed = (int) ($counts[BackupJobStatus::Failed->value] ?? 0);
        $total = $completed + $failed;

        if ($total > 0) {
            $this->successRate = round(($completed / $total) * 100, 1);
        } else {
            $this->successRate = 0;
        }

        $this->runningJobs = BackupJob::forCurrentOrg()->where('status', BackupJobStatus::Running)->count();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
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

    public function render(): View
    {
        return view('livewire.dashboard.success-rate-card');
    }
}
