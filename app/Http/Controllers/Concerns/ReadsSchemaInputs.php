<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ReadsSchemaInputs
{
    /**
     * The submitted `inputs` tree exactly as sent. Laravel's global TrimStrings and ConvertEmptyStringsToNull
     * rewrite request strings, but schema inputs are provider data checked by JSON Schema: an empty source
     * default or significant whitespace must reach validation unchanged. The envelope is validated normally.
     */
    private function schemaInputs(Request $request, array $validated): array
    {
        if (! $request->isJson()) {
            return $validated;
        }
        $body = json_decode($request->getContent(), true);

        return is_array($body) && is_array($body['inputs'] ?? null) ? $body['inputs'] : $validated;
    }
}
