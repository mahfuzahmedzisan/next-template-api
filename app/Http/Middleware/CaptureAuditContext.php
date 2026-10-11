<?php

namespace App\Http\Middleware;

use App\Audit\Support\AuditContext;
use App\Support\ClientContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CaptureAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id', '');
        $requestId = Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        AuditContext::capture($requestId, ClientContext::ip($request), ClientContext::userAgent($request));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
