<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Workstream B: adds a deliberately small set of HTTP security
 * headers to every response, chosen for what this backend actually is — a
 * bearer-token-authenticated JSON API (Sanctum, not cookie/session auth;
 * CORS supports_credentials is false) consumed by a separate-origin Next.js
 * web app and a Flutter app, plus two incidental, non-sensitive HTML routes
 * (`/` — dead demo boilerplate, and the framework's own `/up` health page).
 *
 * Headers evaluated but deliberately NOT added here (see
 * OGCLIENT-SECURITY-WORKSTREAM-B-SECURITY-HEADERS-REPORT.md for the full
 * reasoning, this is the short version):
 *  - Content-Security-Policy: this API returns JSON/binary, not
 *    browser-executable HTML/JS content; a CSP protects a rendered page
 *    from XSS-style resource/script injection, which has no meaning for a
 *    JSON response. Adding one here would be checklist-driven, not
 *    protective.
 *  - Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy /
 *    Cross-Origin-Embedder-Policy: no endpoint in this codebase is loaded
 *    as a browser subresource (<img>/<script>) or relies on a popup +
 *    window.opener relationship with this domain (checked: no
 *    Storage::url()/asset() direct-embed usage, no popup/oauth code
 *    against this domain). COEP in particular can silently break
 *    unrelated cross-origin resource loading; adding it without a
 *    confirmed need would be exactly the "blindly add" this workstream's
 *    brief warns against.
 *
 * X-Powered-By / Server headers (PHP + Apache version disclosure) are a
 * server/php.ini-level concern, not something Laravel middleware can
 * remove — out of scope for an application-code change; documented as a
 * deployment recommendation in the report instead.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->setIfAbsent($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfAbsent($response, 'X-Frame-Options', 'DENY');
        $this->setIfAbsent($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->setIfAbsent($response, 'Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // Browsers ignore Strict-Transport-Security on a plain-HTTP
        // response anyway, but this is deliberately conditional rather
        // than relying on that: it guarantees the header is never even
        // sent unless the connection Laravel actually sees is HTTPS,
        // which is what makes this safe with no environment/APP_ENV check
        // at all — local dev (plain HTTP) never gets it, and any
        // environment that genuinely terminates HTTPS gets it
        // automatically. No `includeSubDomains` or `preload`: neither can
        // be safely added without confirming every subdomain and every
        // future subdomain is HTTPS-only, which this codebase cannot
        // verify (see report, REQUIRES DEPLOYMENT VERIFICATION).
        if ($request->isSecure()) {
            $this->setIfAbsent($response, 'Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /**
     * Never overwrite a header something else in the response pipeline
     * already deliberately set (e.g. a future stronger value) — there is
     * no such case in this codebase today (verified: none of these header
     * names are set anywhere else), but this keeps that true by
     * construction rather than by audit staying accurate forever.
     */
    private function setIfAbsent(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
