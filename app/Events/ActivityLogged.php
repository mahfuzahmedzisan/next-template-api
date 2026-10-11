<?php

namespace App\Events;

use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityLogged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Activity $activity) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.audit')];
    }

    public function broadcastAs(): string
    {
        return 'activity.logged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['activity' => (new ActivityResource($this->activity))->resolve()];
    }
}
