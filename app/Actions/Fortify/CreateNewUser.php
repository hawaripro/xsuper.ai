<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\NotDisposableEmail;
use App\Services\EmailIntelligence;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly EmailIntelligence $email,
    ) {}


    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new NotDisposableEmail,
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $provider = $this->email->provider($input['email']);

        return DB::transaction(function () use ($input, $provider): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'email_provider' => $provider,
                'password' => Hash::make($input['password']),
            ]);

            // Pass the active request so the referral code (session/cookie) and its IP are recorded.
            $this->referrals->attribute($user, request());

            return $user;
        });
    }
}
