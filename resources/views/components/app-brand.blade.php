<a href="/" wire:navigate>
    <!-- Hidden when collapsed -->
    <div {{ $attributes->class(["hidden-when-collapsed"]) }}>
        <div class="flex items-center gap-3 w-fit">
            <x-logo-icon class="w-10 h-10" />
            <span class="font-bold me-3 tracking-wider uppercase bg-gradient-to-r from-cyan-400 via-purple-500 to-purple-600 bg-clip-text text-transparent drop-shadow-[0_0_10px_rgba(6,182,212,0.3)]">
                Databasement
            </span>
        </div>
    </div>

    <!-- Display when collapsed -->
    <div class="display-when-collapsed hidden mx-5 mt-5 mb-1 h-[28px]">
        <x-logo-icon class="w-7 h-7" />
    </div>
</a>
