<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;

class ChatConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $user->id === $conversation->user_id || $user->isAdmin();
    }

    public function delete(User $user, ChatConversation $conversation): bool
    {
        return $user->id === $conversation->user_id || $user->isAdmin();
    }
}
