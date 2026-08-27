<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * doc 08 §1 — Accept-Language drives every localized string in the response, including
 * `message`. Safe to run as global API middleware: it only reads the header, never
 * $request->user(), so it has none of the ordering problem EnsureClientMatchesUserType had.
 */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locale::current($request));

        return $next($request);
    }
}
