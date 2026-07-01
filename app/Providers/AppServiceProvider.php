<?php

namespace App\Providers;

use App\Support\TraceContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One TraceContext per request so the retrieval tool and the generation
        // middleware share the same current-trace id.
        $this->app->scoped(TraceContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
