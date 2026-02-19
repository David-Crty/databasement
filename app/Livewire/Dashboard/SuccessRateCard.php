<?php

namespace App\Livewire\Dashboard;

use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class SuccessRateCard extends Component
{
    public float $successRate = 0;

    public int $runningJobs = 0;

    public function mount(): void
    {
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
