<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('admin.audit', function (User $user): bool {
    return $user->isAdmin();
});
