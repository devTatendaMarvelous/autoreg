# WebSocket Implementation for Dahua API

The Dahua API routes have been extended to support real-time updates via WebSockets using [Laravel Reverb](https://reverb.laravel.com/).

## Overview

Each REST API call now broadcasts a corresponding event on the `dahua.events` channel. This allows any WebSocket client (e.g. a dashboard, monitoring tool) to see device registration and heartbeats in real-time.

### Events

| REST Route | WebSocket Event | Payload |
| --- | --- | --- |
| `/cgi-bin/api/autoRegist/connect` | `dahua.connected` | `{ "ip": "192.168.1.100" }` |
| `/cgi-bin/api/global/login` | `dahua.logged_in` | `{ "ip": "192.168.1.100", "token": "..." }` |
| `/cgi-bin/api/global/keep-alive` | `dahua.heartbeat` | `{ "ip": "192.168.1.100" }` |

## Usage (Client-Side)

Using [Laravel Echo](https://laravel.com/docs/broadcasting#client-side-installation):

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

window.Echo.channel('dahua.events')
    .listen('.dahua.connected', (e) => {
        console.log('Device connected:', e.ip);
    })
    .listen('.dahua.logged_in', (e) => {
        console.log('Device logged in:', e.ip, 'Token:', e.token);
    })
    .listen('.dahua.heartbeat', (e) => {
        console.log('Heartbeat received from:', e.ip);
    });
```

## Running the WebSocket Server

To start the Reverb server, run:

```bash
php artisan reverb:start
```
