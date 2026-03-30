<?php


class HttpServer
{
    private $server;
    private $clients = [];
    private $lastResponses = [];
    private $deviceMap = ['corepay'];      // DeviceID => socket
    private $tokenMap = [];       // socket id => token
    private $controlServer;
    private $username;
    private $password;

    public function __construct($ip, $port, $username, $password)
    {
        $this->server = stream_socket_server("tcp://$ip:$port", $errno, $errstr);

        // 👇 NEW: control port (e.g. 9001)
        $this->controlServer = stream_socket_server("tcp://127.0.0.1:9001", $e2, $eStr);

        if (!$this->server || !$this->controlServer) {
            die("Error: $errstr ($errno)\n");
        }

        stream_set_blocking($this->server, false);
        stream_set_blocking($this->controlServer, false);

        $this->username = $username;
        $this->password = $password;

        echo "Server started at $ip:$port\n";
        echo "Control server at 127.0.0.1:9001\n";
    }

    public function start()
    {
        while (true) {

            // 👇 Accept device connections
            $client = @stream_socket_accept($this->server, 0);
            if ($client) {
                stream_set_blocking($client, false);
                $this->clients[] = $client;
                echo "New client connected\n";
            }

            // 👇 Accept control commands
            $control = @stream_socket_accept($this->controlServer, 0);
            if ($control) {

                $input = fread($control, 2048);

                if (!$input) {
                    fclose($control);
                    continue;
                }

                $cmd = json_decode($input, true);

                if (!$cmd) {
                    fwrite($control, json_encode([
                        "error" => "Invalid JSON",
                        "raw" => $input
                    ]));
                    fclose($control);
                    continue;
                }

                $result = $this->request(
                    $cmd['deviceId'],
                    $cmd['method'],
                    $cmd['uri'],
                    $cmd['data'] ?? ""
                );

                fwrite($control, json_encode([
                    "status" => $result ?? "failed"
                ]));

                fclose($control);
            }

            // 👇 Handle device messages
            foreach ($this->clients as $client) {

                $data = fread($client, 2048);
                if (!$data) continue;

                echo "Received:\n$data\n";
                $this->handleRequest($client, $data);
            }

            usleep(100000); // prevent CPU burn
        }
    }

    private function handleRequest($client, $msg)
    {
        // 1. 设备注册
        if (strpos($msg, "/cgi-bin/api/autoRegist/connect") !== false) {

            if (preg_match('/"DeviceID"\s*:\s*"(\w+)"/', $msg, $match)) {
                $deviceId = $match[1];
                $this->deviceMap[$deviceId] = $client;
                echo "Device registered: $deviceId\n";
            }

            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

            // 发起登录请求
            sleep(1);
            $this->sendLogin($client);

        } // 2. 401认证
        elseif (strpos($msg, "401 Unauthorized") !== false) {

            $auth = $this->parseDigest($msg);
            $this->sendDigestLogin($client, $auth);

        } // 3. 获取Token
        elseif (preg_match('/"Token"\s*:\s*"(\w+)"/', $msg, $match)) {

            $token = $match[1];
            $id = intval($client);
            $this->tokenMap[$id] = $token;

            echo "Token received: $token\n";

            // 启动心跳（简单实现）
            $this->startHeartbeat($client, $token);
        }
    }

    private function sendLogin($client)
    {
        $req =
            "POST /cgi-bin/api/global/login HTTP/1.1\r\n" .
            "Content-Length: 0\r\n\r\n";

        fwrite($client, $req);
    }

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

    private function sendDigestLogin($client, $auth)
    {
        $uri = "/cgi-bin/api/global/login";
        $method = "POST";

        $nc = "00000001";
        $cnonce = md5(uniqid());

        // HA1
        $ha1 = md5("{$this->username}:{$auth['realm']}:{$this->password}");

        // HA2
        $ha2 = md5("$method:$uri");

        // response
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

        fwrite($client, $req);
    }

    private function startHeartbeat($client, $token)
    {
        // ⚠️ PHP没有原生线程，这里用简单模拟（生产建议用 Swoole 或定时任务）
        for ($i = 0; $i < 5; $i++) {
            sleep(20);

            $req =
                "POST /cgi-bin/api/global/keep-alive HTTP/1.1\r\n" .
                "X-cgi-token: $token\r\n" .
                "Content-Length: 0\r\n\r\n";

            fwrite($client, $req);
            echo "Heartbeat sent\n";
        }
    }

    public function request($deviceId, $method, $uri, $data = "")
    {
        if (!isset($this->deviceMap[$deviceId])) {
            echo "Device not found\n";
            return false;
        }

        $client = $this->deviceMap[$deviceId];
        $token = $this->tokenMap[intval($client)] ?? "";

        // Build request
        $req = "$method $uri HTTP/1.1\r\n";
        if (!empty($token)) {
            $req .= "X-cgi-token: $token\r\n";
        }
        $req .= "Content-Length: " . strlen($data) . "\r\n\r\n";
        $req .= $data;

        fwrite($client, $req);

        // For GET file download, capture the response fully
        if (strtoupper($method) === 'GET' && strpos($uri, 'downloadFile') !== false) {
            $raw = '';
            stream_set_blocking($client, true); // make sure fread waits
            while (!feof($client)) {
                $chunk = fread($client, 8192);
                if ($chunk === false) break;
                $raw .= $chunk;
            }

            // Strip HTTP headers
            if (strpos($raw, "\r\n\r\n") !== false) {
                $parts = explode("\r\n\r\n", $raw, 2);
                $fileData = $parts[1] ?? '';
            } else {
                $fileData = $raw;
            }
            echo "File downloaded: " . strlen($fileData) . " bytes\n";

            return $fileData; // return actual file content
        }

        // For normal API calls, just return "sent"
        return ["status" => "sent"];
    }
}
