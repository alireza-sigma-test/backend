<?php

// app/Providers/RepositoryServiceProvider.php

namespace App\Providers;

use App\Repositories\Contracts\ProposalRepository;
use App\Repositories\Eloquent\EloquentProposalRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        ProposalRepository::class => EloquentProposalRepository::class,
    ];
}
