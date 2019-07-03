<?php

namespace InnotecScotlandLtd\Gazette\Providers;

use Illuminate\Support\ServiceProvider;


class GazetteServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config' => base_path('config'),
        ]);
    }

    public function register()
    {

    }
}