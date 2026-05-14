<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UnipinAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    // UnipinAuth.php
    public function handle($request, Closure $next)
    {
        if (!session()->has('unipin_cookies')) {
            return redirect()->route('login');
        }
        return $next($request);
    }
}
