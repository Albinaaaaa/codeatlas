<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED_LOCALES = ['en', 'uk'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', config('app.locale'));

        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = 'en';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
