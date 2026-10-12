<?php

namespace App\Providers;

use App\Ai\Listeners\TraceGeneration;
use App\Support\TraceContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One TraceContext per request so the retrieval tool and the generation
        // listener share the same current-trace id.
        $this->app->scoped(TraceContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Streamed runs fire subclasses of these events, and Laravel matches a
        // listener to the exact event class, so register both pairs.
        Event::listen([PromptingAgent::class, StreamingAgent::class], [TraceGeneration::class, 'start']);
        Event::listen([AgentPrompted::class, AgentStreamed::class], [TraceGeneration::class, 'finish']);
    }
}
