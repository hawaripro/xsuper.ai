<?php

namespace App\Media\Enums;

/**
 * The four distinct outcomes of an adapter submit. A submit timeout is `Uncertain`,
 * NOT `Rejected`: uncertain acceptance must be reconciled, never treated as failure
 * and never followed by a new paid request.
 */
enum SubmitOutcome: string
{
    case Immediate = 'immediate';  // completed synchronously; resultUrls present
    case Accepted = 'accepted';    // accepted as a job; taskId present
    case Rejected = 'rejected';    // definitively rejected by the provider
    case Uncertain = 'uncertain';  // acceptance unknown (e.g. timeout); reconcile
}
