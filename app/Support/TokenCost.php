<?php

namespace App\Support;

use Laravel\Ai\Responses\Data\Usage;

/**
 * Turns a response's token usage into a dollar cost using the rate card in
 * config/observability.php. The model response carries token counts but never
 * a price — cost is yours to compute.
 */
class TokenCost
{
    public static function usd(string $model, Usage $usage): float
    {
        $rates = config('observability.pricing');
        $rate = $rates[$model] ?? $rates['default'];

        return round(
            $usage->promptTokens / 1_000_000 * $rate['input']
            + $usage->completionTokens / 1_000_000 * $rate['output'],
            6,
        );
    }
}
