<?php

namespace App\Rules;

use App\Services\EmailIntelligence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects throwaway/temporary inbox providers at registration.
 */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('email_intelligence.block_disposable', true)) {
            return;
        }

        if (is_string($value) && app(EmailIntelligence::class)->isDisposable($value)) {
            $fail('Gunakan alamat email tetap. Email sekali pakai tidak diperbolehkan.');
        }
    }
}
