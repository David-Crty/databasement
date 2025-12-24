<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithDeferredLoading;
use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class JobsActivityChart extends Component
{
    use WithDeferredLoading;

    /** @var array<string, mixed> */
    public array $chart = [];

    protected function loadContent(): void
    {
        $days = 14;
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();

        $jobs = BackupJob::where('created_at', '>=', $startDate)
            ->get()
            ->groupBy(fn ($job) => $job->created_at->format('Y-m-d'));

        $labels = [];
        $completed = [];
        $failed = [];
        $running = [];
        $pending = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $labels[] = $date->format('M j');

            /** @var Collection<int, BackupJob> $dayJobs */
            $dayJobs = $jobs->get($dateKey, collect());

            $completed[] = $dayJobs->where('status', 'completed')->count();
            $failed[] = $dayJobs->where('status', 'failed')->count();
            $running[] = $dayJobs->where('status', 'running')->count();
            $pending[] = $dayJobs->where('status', 'pending')->count();
        }

        $this->chart = [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => __('Completed'),
                        'data' => $completed,
                        'backgroundColor' => '--color-success',
                        'borderRadius' => 4,
                    ],
                    [
                        'label' => __('Failed'),
                        'data' => $failed,
                        'backgroundColor' => '--color-error',
                        'borderRadius' => 4,
                    ],
                    [
                        'label' => __('Running'),
                        'data' => $running,
                        'backgroundColor' => '--color-warning',
                        'borderRadius' => 4,
                    ],
                    [
                        'label' => __('Pending'),
                        'data' => $pending,
                        'backgroundColor' => '--color-info',
                        'borderRadius' => 4,
                    ],
                ],
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'scales' => [
                    'x' => [
                        'stacked' => true,
                        'grid' => [
                            'display' => false,
                        ],
                    ],
                    'y' => [
                        'stacked' => true,
                        'beginAtZero' => true,
                        'ticks' => [
                            'stepSize' => 1,
                        ],
                    ],
                ],
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                    ],
                ],
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.dashboard.jobs-activity-chart');
    }
}
