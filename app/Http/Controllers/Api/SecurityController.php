<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecuritySetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

class SecurityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $validated = $request->validate([
            'enforce_admin_ip' => ['required', 'boolean'],
            'require_admin_2fa' => ['required', 'boolean'],
            'admin_ip_allowlist' => ['present', 'array', 'max:100'],
            'admin_ip_allowlist.*' => ['string', 'max:64'],
        ]);

        $entries = [];
        foreach ($validated['admin_ip_allowlist'] as $raw) {
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }
            // Accept a bare IP or CIDR; reject anything IpUtils cannot evaluate.
            if (! $this->isValidCidrOrIp($value)) {
                throw ValidationException::withMessages(['admin_ip_allowlist' => "Entri IP/CIDR tidak valid: {$value}"]);
            }
            $entries[] = $value;
        }
        $entries = array_values(array_unique($entries));

        // Guard against self-lockout: enabling enforcement with a list that does
        // not include the admin's current IP is refused.
        if ($validated['enforce_admin_ip'] && $entries !== [] && ! IpUtils::checkIp((string) $request->ip(), $entries)) {
            throw ValidationException::withMessages([
                'admin_ip_allowlist' => 'Daftar tidak menyertakan IP Anda saat ini ('.$request->ip().'). Tambahkan dulu agar tidak terkunci.',
            ]);
        }

        $settings = SecuritySetting::current();
        $settings->fill([
            'enforce_admin_ip' => $validated['enforce_admin_ip'],
            'require_admin_2fa' => $validated['require_admin_2fa'],
            'admin_ip_allowlist' => $entries,
            'updated_by' => $request->user()->id,
        ])->save();

        $audit->record($request->user(), 'security.settings.updated', $settings, [
            'after' => $settings->only(['enforce_admin_ip', 'require_admin_2fa', 'admin_ip_allowlist']),
        ]);

        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $settings = SecuritySetting::current();
        $ip = (string) $request->ip();

        return [
            'settings' => [
                'enforce_admin_ip' => $settings->enforce_admin_ip,
                'require_admin_2fa' => $settings->require_admin_2fa,
                'admin_ip_allowlist' => array_values((array) $settings->admin_ip_allowlist),
            ],
            'current_ip' => $ip,
            'suggested_entry' => $this->suggestEntry($ip),
        ];
    }

    /**
     * Mobile networks hand out IPv6 addresses whose interface identifier rotates
     * (privacy extensions), so pinning a /128 locks the admin out within hours.
     * Suggest the /64 prefix instead; IPv4 is stable enough to use as-is.
     */
    private function suggestEntry(string $ip): string
    {
        if ($ip === '' || ! str_contains($ip, ':')) {
            return $ip;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        $prefix = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $prefix === false ? $ip : $prefix.'/64';
    }

    private function isValidCidrOrIp(string $value): bool
    {
        if (str_contains($value, '/')) {
            [$ip, $mask] = explode('/', $value, 2);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false
                && ctype_digit($mask)
                && (int) $mask >= 0
                && (int) $mask <= (str_contains($ip, ':') ? 128 : 32);
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
