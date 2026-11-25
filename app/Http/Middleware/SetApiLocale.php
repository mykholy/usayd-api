<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        $locale = $request->getPreferredLanguage(['ar', 'en']) ?? 'ar';
        app()->setLocale($locale);
        $request->attributes->set('api_locale', $locale);

        try {
            $response = $next($request);
            if (! $response->headers->has('Content-Language')) {
                $response->headers->set('Content-Language', $locale);
            }
            $response->setVary('Accept-Language', false);

            return $response;
        } finally {
            app()->setLocale($previous);
        }
    }
}
