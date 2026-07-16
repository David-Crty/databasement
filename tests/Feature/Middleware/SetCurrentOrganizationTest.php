<?php

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Services\CurrentOrganization;
use Illuminate\Http\Request;

test('a guest request clears organization state left over from a prior request', function () {
    // Simulate leftover state from a previous authenticated request: the service
    // is a singleton and survives across requests in worker runtimes.
    $current = app(CurrentOrganization::class);
    $current->switchTo(Organization::factory()->create());
    expect($current->isResolved())->toBeTrue();

    // A guest request (no authenticated user) must reset it, so ScopeBouncer
    // falls back to the global (null) scope instead of the prior user's org.
    app(SetCurrentOrganization::class)->handle(
        Request::create('/'),
        fn (Request $request) => response('ok'),
    );

    expect($current->isResolved())->toBeFalse();
});
