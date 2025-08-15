<?php

namespace Themosis\Core\Providers;

use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Support\AggregateServiceProvider;

class ConsoleCoreServiceProvider extends AggregateServiceProvider
{
    /**
     * The provider class ConsoleCoreServiceProvider.
     *
     * @var array
     */
    protected $providers = [
        ArtisanServiceProvider::class,
        MigrationServiceProvider::class,
        ComposerServiceProvider::class,
    ];
}
