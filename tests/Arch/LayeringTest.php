<?php
// tests/Arch/LayeringTest.php

arch('enums are string-backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('no debug statements ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
