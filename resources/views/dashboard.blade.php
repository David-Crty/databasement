<x-layouts.app :title="__('Dashboard')">
    <x-header title="{{ __('Dashboard') }}" separator />

    <div class="flex flex-col gap-6">
        {{-- Stats Cards Row --}}
        <livewire:dashboard.stats-cards />

        {{-- Jobs Activity Chart --}}
        <livewire:dashboard.jobs-activity-chart />

        {{-- Bottom Row: Latest Jobs + Charts --}}
        <div class="grid gap-6 lg:grid-cols-3 items-start">
            <div class="lg:col-span-2 h-full">
                <livewire:dashboard.latest-jobs />
            </div>
            <div class="grid grid-rows-2 gap-6 h-full">
                <livewire:dashboard.success-rate-chart />
                <livewire:dashboard.storage-distribution-chart />
            </div>
        </div>
    </div>
</x-layouts.app>
