<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', static function (User $user, string $id): bool {
    return ctype_digit($id) && (string) $user->getAuthIdentifier() === $id;
});
