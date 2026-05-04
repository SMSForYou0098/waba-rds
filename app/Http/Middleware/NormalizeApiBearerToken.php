<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiBearerToken
{
    /**
     * Normalize token inputs so Passport can authenticate consistently.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->headers->has('Authorization')) {
            $token = $request->bearerToken()
                ?? $request->header('token')
                ?? $request->header('access_token')
                ?? $request->input('token')
                ?? $request->input('access_token');

            if (is_string($token)) {
                $token = trim($token);

                if ($token !== '') {
                    if (stripos($token, 'Bearer ') !== 0) {
                        $token = 'Bearer ' . $token;
                    }

                    $request->headers->set('Authorization', $token);
                }
            }
        }

        return $next($request);
    }
}
