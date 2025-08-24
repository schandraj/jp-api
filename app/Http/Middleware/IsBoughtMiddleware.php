<?php

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsBoughtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
