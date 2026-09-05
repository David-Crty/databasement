<div>
    <x-header :title="__('Changelog')" :subtitle="__('What changed in each release of Databasement')" separator>
        <x-slot:actions>
            <x-button
                :label="__('GitHub releases')"
                icon="bi.github"
                link="{{ config('app.github_repo') }}/releases"
                external
                class="btn-ghost btn-sm"
            />
        </x-slot:actions>
    </x-header>

    @php
        $sectionBadges = [
            'Added' => 'badge-success',
            'Changed' => 'badge-info',
            'Deprecated' => 'badge-ghost',
            'Removed' => 'badge-neutral',
            'Fixed' => 'badge-warning',
            'Security' => 'badge-error',
        ];
        $sectionLabels = [
            'Added' => __('Added'),
            'Changed' => __('Changed'),
            'Deprecated' => __('Deprecated'),
            'Removed' => __('Removed'),
            'Fixed' => __('Fixed'),
            'Security' => __('Security'),
        ];
    @endphp

    <div class="space-y-4">
        @forelse ($this->releases as $release)
            @php
                $isCurrent = $this->isCurrent($release);
                $isNew = ! $isCurrent && $this->isNewerThanCurrent($release->version);
            @endphp
            <div id="{{ $release->isUnreleased() ? 'unreleased' : 'v'.$release->version }}" class="scroll-mt-4">
                <x-card shadow class="{{ $isCurrent ? 'ring-2 ring-primary/40' : '' }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 mb-4">
                        <span class="font-mono text-lg font-semibold">
                            {{ $release->isUnreleased() ? __('Unreleased') : 'v'.$release->version }}
                        </span>
                        @if ($release->latestVersion && $release->latestVersion !== $release->version)
                            <x-badge :value="$release->latestVersion" class="badge-ghost badge-sm font-mono" :title="__('Latest patch')" />
                        @endif
                        @if ($release->date)
                            <span class="text-sm text-base-content/60">{{ $release->date }}</span>
                        @endif
                        @if ($isCurrent)
                            <x-badge :value="__('Current')" class="badge-primary badge-sm" />
                        @elseif ($isNew)
                            <x-badge :value="__('New')" class="badge-warning badge-sm" />
                        @endif
                        @if ($release->url)
                            <a href="{{ $release->url }}" target="_blank" rel="noopener" class="ml-auto inline-flex items-center gap-1 text-sm link link-hover text-base-content/60">
                                <x-bi-github class="w-4 h-4" />
                                {{ __('Compare on GitHub') }}
                            </a>
                        @endif
                    </div>

                    @forelse ($release->sections as $name => $entries)
                        <div class="mb-4 last:mb-0">
                            <x-badge :value="$sectionLabels[$name] ?? $name" class="{{ $sectionBadges[$name] ?? 'badge-ghost' }} badge-sm mb-2" />
                            <ul class="list-disc ml-5 space-y-1 text-sm [&_a]:underline [&_a]:text-primary [&_a:hover]:opacity-80 [&_code]:font-mono [&_code]:text-xs [&_code]:bg-base-200 [&_code]:px-1 [&_code]:rounded">
                                @foreach ($entries as $entry)
                                    <li>
                                        @if ($entry['version'])
                                            <span class="badge badge-xs font-mono align-middle mr-1 {{ $this->isNewerThanCurrent($entry['version']) ? 'badge-warning' : 'badge-ghost' }}">{{ $entry['version'] }}</span>
                                        @endif
                                        {!! $entry['html'] !!}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-base-content/60">{{ __('No changes recorded yet') }}</p>
                    @endforelse
                </x-card>
            </div>
        @empty
            <x-card shadow>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <x-icon name="o-document-text" class="w-10 h-10 text-base-content/30 mb-3" />
                    <p class="font-medium">{{ __('No changelog is available for this build') }}</p>
                </div>
            </x-card>
        @endforelse
    </div>
</div>
