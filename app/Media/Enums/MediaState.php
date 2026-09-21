<?php

namespace App\Media\Enums;

/** Normalized generation status from an adapter poll. */
enum MediaState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
