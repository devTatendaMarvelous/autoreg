<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;


class SwooleDeviceServer extends Command
{
    protected $signature = 'swoole';
    protected $description = 'Start Swoole Device Server';

    private $server;
    private $deviceMap = []; // deviceId => fd
    private $tokenMap = [];  // fd => token

    private $username = 'admin';
    private $password = 'password';

    public function handle()
    {
        $this->server = new Server("0.0.0.0", 9501);

        $this->server->set([
            'worker_num' => 2,
            'daemonize' => false
        ]);

        // 🔌 Client connects
        $this->server->on('Connect', function ($server, $fd) {
            echo "Client connected: {$fd}\n";
        });

        // 📩 Data received
        $this->server->on('Receive', function ($server, $fd, $reactorId, $data) {

            echo "Received from {$fd}:\n$data\n";

            // 1. Device register
            if (strpos($data, "/cgi-bin/api/autoRegist/connect") !== false) {

                if (preg_match('/"DeviceID"\s*:\s*"(\w+)"/', $data, $match)) {
                    $deviceId = $match[1];
                    $this->deviceMap[$deviceId] = $fd;

                    echo "Device registered: $deviceId\n";
                }

                $server->send($fd, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

                // async login trigger
                $this->sendLogin($server, $fd);
            }

            // 2. 401 digest auth
            elseif (strpos($data, "401 Unauthorized") !== false) {

                $auth = $this->parseDigest($data);
                $this->sendDigestLogin($server, $fd, $auth);
            }

            // 3. Token received
            elseif (preg_match('/"Token"\s*:\s*"(\w+)"/', $data, $match)) {

                $token = $match[1];
                $this->tokenMap[$fd] = $token;

                echo "Token received: $token\n";

                $this->startHeartbeat($server, $fd, $token);
            }
        });

        $this->server->on('Close', function ($server, $fd) {
            echo "Client {$fd} disconnected\n";

            unset($this->tokenMap[$fd]);

            foreach ($this->deviceMap as $id => $clientFd) {
                if ($clientFd === $fd) {
                    unset($this->deviceMap[$id]);
                }
            }
        });

        echo "🔥 Swoole server started on 0.0.0.0:9501\n";
        $this->server->start();
    }

    // 🔐 Send initial login
    private function sendLogin($server, $fd)
    {
        $req = "POST /cgi-bin/api/global/login HTTP/1.1\r\nContent-Length: 0\r\n\r\n";
        $server->send($fd, $req);
    }

    // 🧩 Parse digest
    private function parseDigest($msg)
    {
        $fields = ["realm", "nonce", "qop", "opaque"];
        $result = [];

        foreach ($fields as $field) {
            if (preg_match("/$field=\"([^\"]+)\"/", $msg, $m)) {
                $result[$field] = $m[1];
            }
        }

        return $result;
    }

    // 🔑 Digest login
    private function sendDigestLogin($server, $fd, $auth)
    {
        $uri = "/cgi-bin/api/global/login";
        $method = "POST";

        $nc = "00000001";
        $cnonce = md5(uniqid());

        $ha1 = md5("{$this->username}:{$auth['realm']}:{$this->password}");
        $ha2 = md5("$method:$uri");

        $response = md5("$ha1:{$auth['nonce']}:$nc:$cnonce:{$auth['qop']}:$ha2");

        $header =
            "Authorization: Digest username=\"{$this->username}\", " .
            "realm=\"{$auth['realm']}\", nonce=\"{$auth['nonce']}\", uri=\"$uri\", " .
            "response=\"$response\", opaque=\"{$auth['opaque']}\", " .
            "qop={$auth['qop']}, nc=$nc, cnonce=\"$cnonce\"";

        $req =
            "POST $uri HTTP/1.1\r\n" .
            "$header\r\n" .
            "Content-Length: 0\r\n\r\n";

        $server->send($fd, $req);
    }

    // ❤️ Heartbeat (NON-BLOCKING MAGIC)
    private function startHeartbeat($server, $fd, $token)
    {
        \Swoole\Timer::tick(20000, function () use ($server, $fd, $token) {

            if (!$server->isEstablished($fd)) {
                return;
            }

            $req =
                "POST /cgi-bin/api/global/keep-alive HTTP/1.1\r\n" .
                "X-cgi-token: $token\r\n" .
                "Content-Length: 0\r\n\r\n";

            $server->send($fd, $req);

            // 🔥 Trigger other requests ANYTIME
            $this->request($server, 'corepay', 'POST', 'cgi-bin/api/AccessAppHelper/attachUSB');

            $data = json_encode([
                "exportType" => "ShiftInfo",
                "method" => 1,
                "startTime" => "2025-03",
                "endTime" => "2025-03"
            ]);

            $this->request($server, 'corepay', 'POST', 'cgi-bin/api/AccessAppHelper/exportUSB', $data);

            echo "Heartbeat + tasks sent to {$fd}\n";
        });
    }

    // 🚀 External trigger method
    public function request($server, $deviceId, $method, $uri, $data = "")
    {
        if (!isset($this->deviceMap[$deviceId])) {
            echo "Device not found\n";
            return;
        }

        $fd = $this->deviceMap[$deviceId];
        $token = $this->tokenMap[$fd] ?? "";

        $req =
            "$method $uri HTTP/1.1\r\n" .
            "X-cgi-token: $token\r\n" .
            "Content-Length: " . strlen($data) . "\r\n\r\n" .
            $data;

        $server->send($fd, $req);

        echo "Request sent to $deviceId\n";
    }
}
