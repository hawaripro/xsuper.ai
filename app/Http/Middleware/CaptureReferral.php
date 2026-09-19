<?php

namespace App\Http\Middleware;

use App\Services\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers a `?ref=CODE` referral link on any GET landing so the referrer is
 * still attributed when the visitor registers later. Capture is best-effort:
 * unknown codes are ignored and the request is never blocked.
 */
class CaptureReferral
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && is_string($request->query('ref'))) {
            try {
                $this->referrals->remember($request, (string) $request->query('ref'));
            } catch (\Throwable) {
                // Never let referral capture interfere with page loads.
            }
        }

        return $next($request);
    }
}
