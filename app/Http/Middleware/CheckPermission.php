<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next,...$permissions): Response
    {
        \Log::info('Permissions received: ' . json_encode($permissions));
        if (Auth::check() && Auth::user()->hasAnyPermission($permissions)) {
            return $next($request);
        }

        return response()->json([
            'status' => false,
            'message' => 'Unauthorized. You do not have permission to access this resource.'
        ], 403);
        // return $next($request);
    }
}
