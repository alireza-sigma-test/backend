<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 11+'s base Controller ships empty. $this->authorize(...) is the
    // brief's required call style, so the trait is added here rather than
    // per-controller.
    use AuthorizesRequests;
}
