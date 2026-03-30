<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DahuaConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ip;

    public function __construct($ip)
    {
        $this->ip = $ip;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('dahua.events'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dahua.connected';
    }
}
