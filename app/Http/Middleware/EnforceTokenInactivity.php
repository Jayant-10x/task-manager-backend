<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnforceTokenInactivity {
    private const IDLE_TIMEOUT = 3600;

    public function handle (Request $request, Closure $next): Response {
        $user = $request->user();

        if ($user && !$user->usr_status) {
            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
            return response()->json([
                'message' => 'Account is inactive.',
                'code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        // Apply only to authenticated Sanctum Bearer-token requests.
        if (!$user || !$user->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        $tokenId = $token->getKey();
        $cacheKey = 'token_last_activity:' . $tokenId;

        // Use the token's actual creation time for the first request.
        $lastActivity = Cache::get(
            $cacheKey,
            $token->created_at?->timestamp ?? now()->timestamp
        );

        if (now()->timestamp - (int)$lastActivity >= self::IDLE_TIMEOUT) {
            Cache::forget($cacheKey);

            // Revoke this specific token.
            $token->delete();

            return response()->json([
                'message' => 'Your session expired due to inactivity. Please log in again.',
                'code' => 'SESSION_INACTIVE',
            ], 401);
        }

        // Record successful authenticated activity.
        Cache::put($cacheKey, now()->timestamp, self::IDLE_TIMEOUT);

        return $next($request);
    }
}
