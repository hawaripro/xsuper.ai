<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admission identity spans native and schema executors; deleted work never becomes a new paid replay. */
class WorkspaceMediaSubmission extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'capability_snapshot', 'payload_fingerprint', 'execution'];

    protected function casts(): array
    {
        return ['capability_snapshot' => 'array', 'execution' => 'array', 'job_ids' => 'array'];
    }
}
