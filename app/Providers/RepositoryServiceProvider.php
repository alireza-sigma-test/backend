<?php

// app/Providers/RepositoryServiceProvider.php

namespace App\Providers;

use App\Repositories\Contracts\NotificationRepository;
use App\Repositories\Contracts\ProposalRepository;
use App\Repositories\Contracts\UserRepository;
use App\Repositories\Eloquent\EloquentNotificationRepository;
use App\Repositories\Eloquent\EloquentProposalRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        NotificationRepository::class => EloquentNotificationRepository::class,
        ProposalRepository::class => EloquentProposalRepository::class,
        UserRepository::class => EloquentUserRepository::class,
    ];
}
