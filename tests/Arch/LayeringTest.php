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

arch('services and repositories are final')
    ->expect(['App\Services', 'App\Repositories\Eloquent', 'App\Repositories\Filters'])
    ->toBeFinal();
