<?php

namespace App\Support;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Turns a response's token usage into a dollar cost using the rate card in
 * config/observability.php. The model response carries token counts but never
 * a price — cost is yours to compute.
 *
 * On laravel/ai 1.x inputTokens includes cache reads and cache writes, which
 * providers bill at their own rates, so each part is priced once on its own.
 */
class TokenCost
{
    public static function usd(string $model, TextUsage $usage): float
    {
        $rates = config('observability.pricing');
        $rate = $rates[$model] ?? $rates['default'];

        return round((
            $usage->uncachedInputTokens() * $rate['input']
            + ($usage->cacheReadInputTokens ?? 0) * $rate['cache_read']
            + ($usage->cacheWriteInputTokens ?? 0) * $rate['cache_write']
            + $usage->outputTokens * $rate['output']
        ) / 1_000_000, 6);
    }
}
