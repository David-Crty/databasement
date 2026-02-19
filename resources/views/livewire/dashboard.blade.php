<div class="dashboard">
    <x-header title="{{ __('Dashboard') }}" separator />

    <div class="flex flex-col gap-6">
        {{-- Stats Cards Row --}}
        <div class="grid gap-4 md:grid-cols-3">
            <livewire:dashboard.snapshots-card />
            <livewire:dashboard.storage-card />
            <livewire:dashboard.success-rate-card />
        </div>

        {{-- Job Status Grid --}}
        <livewire:dashboard.job-status-grid />

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
</div>
