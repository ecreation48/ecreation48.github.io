<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Symfony\Component\HttpFoundation\Response;
class AuthenticateServiceToken { public function handle(Request $request, Closure $next): Response { $expected=(string)config('services.worker.token'); $provided=(string)$request->bearerToken(); abort_unless($expected!=='' && hash_equals($expected,$provided),401,'Invalid service credentials.'); return $next($request); } }
