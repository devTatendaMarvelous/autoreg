<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DahuaLoggedIn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ip;
    public $token;

    public function __construct($ip, $token)
    {
        $this->ip = $ip;
        $this->token = $token;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('dahua.events'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dahua.logged_in';
    }
}
