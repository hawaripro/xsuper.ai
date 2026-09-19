<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Classifies registration emails: rejects disposable/temporary inboxes and
 * distinguishes consumer Gmail from Google Workspace (a custom domain whose
 * mail is hosted by Google) via an MX lookup that is cached and fail-open.
 */
class EmailIntelligence
{
    /** @var (callable(string): array<int, string>)|null */
    private $mxResolver;

    /**
     * @param  (callable(string): array<int, string>)|null  $mxResolver  Overridable MX lookup, mainly for tests.
     */
    public function __construct(?callable $mxResolver = null)
    {
        $this->mxResolver = $mxResolver;
    }

    public function domain(string $email): string
    {
        return Str::lower(trim(Str::afterLast(trim($email), '@')));
    }

    public function isDisposable(string $email): bool
    {
        $domain = $this->domain($email);
        if ($domain === '') {
            return false;
        }

        $list = array_map('strtolower', (array) config('email_intelligence.disposable_domains', []));

        // Match the domain itself and any subdomain of a blocked domain (e.g. mail.trashmail.com).
        foreach ($list as $blocked) {
            if ($domain === $blocked || Str::endsWith($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    public function isGmail(string $email): bool
    {
        return in_array($this->domain($email), array_map('strtolower', (array) config('email_intelligence.gmail_domains', [])), true);
    }

    /**
     * True when the domain's mail is hosted by Google but it is not a consumer
     * Gmail address — i.e. a Google Workspace (business) account.
     */
    public function isGoogleWorkspace(string $email): bool
    {
        if ($this->isGmail($email) || $this->isDisposable($email)) {
            return false;
        }

        return $this->hasGoogleMx($this->domain($email));
    }

    /**
     * Coarse provider label stored on the account: disposable | gmail |
     * google_workspace | other. Never throws; MX failures fall back to other.
     */
    public function provider(string $email): string
    {
        if ($this->isDisposable($email)) {
            return 'disposable';
        }
        if ($this->isGmail($email)) {
            return 'gmail';
        }
        if ($this->hasGoogleMx($this->domain($email))) {
            return 'google_workspace';
        }

        return 'other';
    }

    private function hasGoogleMx(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        return (bool) Cache::remember('email_intel:google_mx:'.$domain, now()->addHours(12), function () use ($domain): bool {
            $hosts = $this->resolveMx($domain);
            if ($hosts === []) {
                return false;
            }

            $signatures = array_map('strtolower', (array) config('email_intelligence.google_mx', []));
            foreach ($hosts as $host) {
                $host = strtolower(rtrim($host, '.'));
                foreach ($signatures as $signature) {
                    if ($host === $signature || Str::endsWith($host, '.'.$signature)) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * @return array<int, string>
     */
    private function resolveMx(string $domain): array
    {
        if ($this->mxResolver !== null) {
            return array_values(array_filter(($this->mxResolver)($domain)));
        }

        $hosts = [];
        try {
            if (getmxrr($domain, $hosts) && $hosts !== []) {
                return $hosts;
            }
        } catch (\Throwable) {
            // DNS unavailable: treat as non-Google rather than blocking signup.
        }

        return [];
    }
}
