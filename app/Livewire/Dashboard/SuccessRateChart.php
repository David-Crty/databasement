<?php

namespace App\Livewire\Dashboard;

use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class SuccessRateChart extends Component
{
    /** @var array<string, mixed> */
    public array $chart = [];

    public int $total = 0;

    public function mount(): void
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $jobs = BackupJob::where('created_at', '>=', $thirtyDaysAgo)->get();

        $completed = $jobs->where('status', 'completed')->count();
        $failed = $jobs->where('status', 'failed')->count();
        $running = $jobs->where('status', 'running')->count();
        $pending = $jobs->where('status', 'pending')->count();

        $this->total = $jobs->count();

        $this->chart = [
            'type' => 'doughnut',
            'data' => [
                'labels' => [
                    __('Completed'),
                    __('Failed'),
                    __('Running'),
                    __('Pending'),
                ],
                'datasets' => [
                    [
                        'data' => [$completed, $failed, $running, $pending],
                        'backgroundColor' => [
                            '--color-success',
                            '--color-error',
                            '--color-warning',
                            '--color-info',
                        ],
                        'borderWidth' => 0,
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'cutout' => '60%',
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 16,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'chart']);
    }

    public function render(): View
    {
        return view('livewire.dashboard.success-rate-chart');
    }
}
