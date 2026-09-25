<?php

namespace App\Media\Contracts;

/**
 * An adapter whose provider accepts a client-generated task identity. The identity is created once
 * by buildRequest() and persisted by the caller together with the durable submission marker, before
 * the paid request, so an unknown acceptance can later be reconciled with the provider instead of
 * waiting for manual review. The request is never submitted again.
 */
interface AssignsProviderTaskIds
{
    /** @param  array<string, mixed>  $request  a request returned by buildRequest() */
    public function taskId(array $request): string;
}
