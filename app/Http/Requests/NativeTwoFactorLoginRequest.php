<?php

namespace App\Http\Requests;

use App\Services\LoginAdmission;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class NativeTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    public function validRecoveryCode(): ?string
    {
        $code = parent::validRecoveryCode();
        if ($code) {
            $this->admitDevice();
        }

        return $code;
    }

    public function hasValidCode(): bool
    {
        $valid = parent::hasValidCode();
        if ($valid) {
            $this->admitDevice();
        }

        return $valid;
    }

    private function admitDevice(): void
    {
        // Fortify consumes recovery codes after this validator, but before its valid-factor event.
        if ($denied = app(LoginAdmission::class)->denial($this, $this->challengedUser(), admitDevice: true)) {
            $this->session()->forget(['login.id', 'login.remember']);

            throw new HttpResponseException($denied);
        }
    }
}
