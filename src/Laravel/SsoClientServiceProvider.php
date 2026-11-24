<?php

namespace Voope\SsoClient\Laravel;

use Illuminate\Support\ServiceProvider;

final class SsoClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/voope-sso.php', 'voope-sso');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/voope-sso.php' => config_path('voope-sso.php')], 'voope-sso');
    }
}
