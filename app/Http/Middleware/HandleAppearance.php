<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /** @var list<string> */
    private const APPEARANCES = ['light', 'dark', 'system'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance');

        if (! is_string($appearance) || ! in_array($appearance, self::APPEARANCES, true)) {
            $appearance = 'system';
        }

        View::share('appearance', $appearance);

        return $next($request);
    }
}
