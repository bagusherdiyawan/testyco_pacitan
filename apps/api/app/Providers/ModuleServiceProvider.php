<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (config('modules.providers', []) as $provider) {
            if (! is_subclass_of($provider, ServiceProvider::class)) {
                throw new InvalidArgumentException('Invalid module provider: '.$provider);
            }
            $this->app->register($provider);
        }
    }
}
