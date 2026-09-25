<?php

namespace App\Exceptions;

/** Raised before any byte left the server (configuration, DNS pinning, destination guard): the provider never saw the request. */
final class AiProviderNotSent extends AiProxyException {}
