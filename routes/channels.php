<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// The Chats workspace: one private channel per workspace. Only that
// workspace's own users may subscribe — a wrong tenant id is refused.
Broadcast::channel('chat.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
