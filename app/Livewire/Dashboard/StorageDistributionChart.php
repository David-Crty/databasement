<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithDeferredLoading;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class StorageDistributionChart extends Component
{
    use WithDeferredLoading;

    /** @var array<string, mixed> */
    public array $chart = [];

    public int $totalBytes = 0;

    protected function loadContent(): void
    {
        /** @var \Illuminate\Support\Collection<int, object{name: string, total_size: int}> $storageByVolume */
        $storageByVolume = DB::table('snapshots')
            ->join('volumes', 'snapshots.volume_id', '=', 'volumes.id')
            ->select('volumes.name', DB::raw('SUM(snapshots.file_size) as total_size'))
            ->groupBy('volumes.id', 'volumes.name')
            ->orderByDesc('total_size')
            ->get();

        $this->totalBytes = (int) $storageByVolume->sum('total_size');

        // Format labels with size (e.g., "default-s3 (249 MB)")
        $labels = $storageByVolume->map(function (object $volume): string {
            return $volume->name.' ('.Formatters::humanFileSize((int) $volume->total_size).')';
        })->toArray();
        $data = $storageByVolume->pluck('total_size')->map(fn ($size) => (int) $size)->toArray();

        // Generate colors for each volume using a predefined palette
        $colors = [
            '--color-primary',
            '--color-secondary',
            '--color-accent',
            '--color-info',
            '--color-success',
            '--color-warning',
            '--color-error',
        ];

        $backgroundColors = [];
        foreach ($labels as $index => $label) {
            $backgroundColors[] = $colors[$index % count($colors)];
        }

        $this->chart = [
            'type' => 'doughnut',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'data' => $data,
                        'backgroundColor' => $backgroundColors,
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

    public function getFormattedTotal(): string
    {
        return Formatters::humanFileSize($this->totalBytes);
    }

    public function render(): View
    {
        return view('livewire.dashboard.storage-distribution-chart');
    }
}
