<?php

namespace App\Livewire\Dashboard;

use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class StorageDistributionChart extends Component
{
    /** @var array<string, mixed> */
    public array $chart = [];

    public int $totalBytes = 0;

    public function mount(): void
    {
        /** @var \Illuminate\Support\Collection<int, object{name: string, total_size: int}> $storageByVolume */
        $storageByVolume = Snapshot::query()
            ->join('volumes', 'snapshots.volume_id', '=', 'volumes.id')
            ->selectRaw('volumes.name, SUM(snapshots.file_size) as total_size')
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
        foreach (array_keys($labels) as $index) {
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

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'chart']);
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
