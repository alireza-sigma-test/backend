<?php

// tests/Arch/LayeringTest.php

arch('enums are string-backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('no debug statements ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('actions are final and free of HTTP concerns')
    ->expect('App\Actions')
    ->toBeFinal()
    ->not->toUse('Illuminate\Http\Request');

arch('DTOs are readonly and hold no models')
    ->expect('App\Data')
    ->toBeReadonly()
    ->not->toUse(['Illuminate\Http\Request', 'App\Models']);

// Controllers legitimately name model classes for route-model binding and
// Gate::authorize. What they must never do is run a query — that belongs to a
// repository or an action.
arch('controllers never run queries')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Eloquent\Builder']);

// `App\Repositories\Contracts` was already outside this rule by construction —
// only the Eloquent and Filters namespaces are listed. `App\Services` was
// listed wholesale because Services had no sub-namespace until
// `App\Services\Contracts` arrived, and an interface cannot be final. Ignoring
// it rather than moving the interface elsewhere keeps contracts beside the
// implementations they describe, which is where the repository ones sit too.
arch('services and repositories are final')
    ->expect(['App\Services', 'App\Repositories\Eloquent', 'App\Repositories\Filters'])
    ->toBeFinal()
    ->ignoring('App\Services\Contracts');

// The coverage the exclusion above gives up, restated as the stronger claim:
// nothing but interfaces belongs in a contracts namespace. A concrete class
// hiding there would slip past both rules otherwise.
arch('contracts namespaces hold only interfaces')
    ->expect(['App\Services\Contracts', 'App\Repositories\Contracts'])
    ->toBeInterfaces();
