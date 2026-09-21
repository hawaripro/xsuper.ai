<?php

namespace App\Media;

/** What execution modes a provider adapter actually supports (declared, not assumed). */
final readonly class AdapterSupport
{
    public function __construct(
        public bool $async,
        public bool $polling,
        public bool $webhook,
        public bool $cancel,
    ) {}
}
