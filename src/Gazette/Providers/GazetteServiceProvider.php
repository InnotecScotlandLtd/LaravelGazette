<?php

namespace InnotecScotlandLtd\Gazette\Providers;

use Illuminate\Support\ServiceProvider;


class GazetteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Publish your migrations
        $this->publishes([
            __DIR__.'/../migrations/gazette.php' => base_path('/database/migrations/2019_07_03_141101_create_gazette_migrations_table.php'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../config' => base_path('config'),
        ]);
    }

    public function register()
    {

    }
}