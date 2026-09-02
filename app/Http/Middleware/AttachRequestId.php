<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/03 §2.1 — every envelope carries meta.request_id; §2.2 — it is echoed as
 * X-Request-Id for tracing. An inbound X-Request-Id is honoured so a trace can span
 * the panel and the API.
 */
class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || $requestId === '' || strlen($requestId) > 64) {
            $requestId = (string) str()->ulid();
        }

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
