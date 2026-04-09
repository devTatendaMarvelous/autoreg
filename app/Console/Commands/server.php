<?php

namespace App\Console\Commands;

class HttpServer
{
    private $server;
    private $clients = [];
    private $lastResponses = [];
    // We need 2 sockets per device:
    // - command socket: used for export/download requests (token-based)
    // - subscription socket: used for attachUSB streaming notifications (exportPath progress)
    private $commandMap = [];      // DeviceID => socket
    private $subscriptionMap = []; // DeviceID => socket
    private $tokenMap = [];       // socket id => token
    private $controlServer;
    private $username;
    private $password;
    private $controlPort;
    private $lastHeartbeatAt = [];
    private $heartbeatIntervalSeconds = 20;
    private $lastExportFileByDeviceId = [];
    private $lastExportPathsByDeviceId = [];
    private $lastSidByDeviceId = [];

    private function connectedDeviceIds(): array
    {
        $ids = [];
        foreach ($this->commandMap as $id => $sock) {
            if (!is_resource($sock)) continue;
            if (feof($sock)) continue;
            $ids[] = $id;
        }
        return $ids;
    }

    public function __construct($ip, $port, $username, $password, $controlPort = 9001)
    {
        $this->server = stream_socket_server("tcp://$ip:$port", $errno, $errstr);

        // 👇 NEW: control port (e.g. 9001)
        $this->controlPort = (int)$controlPort;
        $this->controlServer = stream_socket_server("tcp://127.0.0.1:{$this->controlPort}", $e2, $eStr);

        if (!$this->server || !$this->controlServer) {
            die("Error: $errstr ($errno)\n");
        }

        stream_set_blocking($this->server, false);
        stream_set_blocking($this->controlServer, false);

        $this->username = $username;
        $this->password = $password;

        echo "Server started at $ip:$port\n";
        echo "Control server at 127.0.0.1:{$this->controlPort}\n";
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

                // Non-request control actions
                if (($cmd['action'] ?? null) === 'status') {
                    $tokenState = [];
                    $allIds = array_values(array_unique(array_merge(array_keys($this->commandMap), array_keys($this->subscriptionMap))));
                    foreach ($allIds as $id) {
                        $cmdSock = $this->commandMap[$id] ?? null;
                        $subSock = $this->subscriptionMap[$id] ?? null;
                        $cmdAlive = is_resource($cmdSock) && !feof($cmdSock);
                        $subAlive = is_resource($subSock) && !feof($subSock);
                        $cmdToken = $cmdAlive ? ($this->tokenMap[intval($cmdSock)] ?? '') : '';
                        $tokenState[$id] = [
                            'commandConnected' => $cmdAlive,
                            'subscriptionConnected' => $subAlive,
                            'hasToken' => $cmdToken !== '',
                            'lastExportFileName' => $this->lastExportFileByDeviceId[$id] ?? null,
                            'lastExportPaths' => $this->lastExportPathsByDeviceId[$id] ?? null,
                            'lastSid' => $this->lastSidByDeviceId[$id] ?? null,
                        ];
                    }

                    fwrite($control, json_encode([
                        "status" => [
                            "ok" => true,
                            "deviceIds" => array_keys($tokenState),
                            "devices" => $tokenState,
                        ]
                    ]));
                    fclose($control);
                    continue;
                }

                if (($cmd['action'] ?? null) === 'lastExport') {
                    $deviceId = $cmd['deviceId'] ?? null;
                    $fileName = is_string($deviceId) ? ($this->lastExportFileByDeviceId[$deviceId] ?? null) : null;
                    fwrite($control, json_encode([
                        "status" => [
                            "ok" => true,
                            "deviceId" => $deviceId,
                            "fileName" => $fileName,
                        ]
                    ]));
                    fclose($control);
                    continue;
                }

                if (($cmd['action'] ?? null) === 'lastExportPaths') {
                    $deviceId = $cmd['deviceId'] ?? null;
                    $paths = is_string($deviceId) ? ($this->lastExportPathsByDeviceId[$deviceId] ?? null) : null;
                    fwrite($control, json_encode([
                        "status" => [
                            "ok" => true,
                            "deviceId" => $deviceId,
                            "exportPaths" => $paths,
                        ]
                    ]));
                    fclose($control);
                    continue;
                }

                if (($cmd['action'] ?? null) === 'lastSid') {
                    $deviceId = $cmd['deviceId'] ?? null;
                    $sid = is_string($deviceId) ? ($this->lastSidByDeviceId[$deviceId] ?? null) : null;
                    fwrite($control, json_encode([
                        "status" => [
                            "ok" => true,
                            "deviceId" => $deviceId,
                            "sid" => $sid,
                        ]
                    ]));
                    fclose($control);
                    continue;
                }

                if (($cmd['action'] ?? null) === 'ping') {
                    $deviceId = $cmd['deviceId'] ?? null;
                    if (!is_string($deviceId) || $deviceId === '') {
                        fwrite($control, json_encode([
                            "status" => [
                                "ok" => false,
                                "error" => "Missing deviceId for ping",
                            ]
                        ]));
                        fclose($control);
                        continue;
                    }

                    $result = $this->request(
                        $deviceId,
                        'POST',
                        '/cgi-bin/api/global/keep-alive',
                        ''
                    );

                    fwrite($control, json_encode([
                        "status" => $result ?? "failed"
                    ]));
                    fclose($control);
                    continue;
                }

                if (!isset($cmd['deviceId'], $cmd['method'], $cmd['uri'])) {
                    fwrite($control, json_encode([
                        "status" => [
                            "ok" => false,
                            "error" => "Missing required fields: deviceId/method/uri",
                            "receivedKeys" => array_keys($cmd),
                        ]
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
                if (!is_resource($client)) {
                    continue;
                }
                if (feof($client)) {
                    $deviceId = $this->getDeviceIdForClient($client);
                    if (is_string($deviceId) && $deviceId !== '') {
                        $this->disconnectClient($deviceId, $client);
                    } else {
                        @fclose($client);
                    }
                    continue;
                }

                $data = fread($client, 2048);
                if (!$data) continue;

                echo "Received:\n$data\n";
                $this->lastResponses[intval($client)] = ($this->lastResponses[intval($client)] ?? '') . $data;
                $this->handleRequest($client, $data);

                // Log export progress notifications so we can see exportPath/state/progress.
                if (
                    stripos($data, '"exportPath"') !== false ||
                    stripos($data, '"progress"') !== false ||
                    stripos($data, '"action"') !== false
                ) {
                    $this->appendAttendanceEventLog($client, $data);
                }
            }

            // 👇 Non-blocking heartbeats (keep-alive)
            $now = time();
            foreach ($this->commandMap as $deviceId => $client) {
                if (!is_resource($client) || feof($client)) continue;
                $clientId = intval($client);
                $token = $this->tokenMap[$clientId] ?? null;
                if (!$token) continue;

                $last = $this->lastHeartbeatAt[$clientId] ?? 0;
                if (($now - $last) >= $this->heartbeatIntervalSeconds) {
                    $req =
                        "POST /cgi-bin/api/global/keep-alive HTTP/1.1\r\n" .
                        "X-cgi-token: $token\r\n" .
                        "Content-Length: 0\r\n\r\n";
                    @fwrite($client, $req);
                    $this->lastHeartbeatAt[$clientId] = $now;
                }
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
                // Prefer to keep a separate command socket even if we already have a subscription socket.
                $subSock = $this->subscriptionMap[$deviceId] ?? null;
                if (!isset($this->commandMap[$deviceId]) || $this->commandMap[$deviceId] === $subSock) {
                    $this->commandMap[$deviceId] = $client;
                }
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
        elseif (preg_match('/"Token"\s*:\s*"([^"]+)"/', $msg, $match)) {

            $token = $match[1];
            $id = intval($client);
            $this->tokenMap[$id] = $token;

            echo "Token received: $token\n";
            $this->lastHeartbeatAt[$id] = 0;
        }

        // 4. Capture export file name notifications (best-effort)
        // 4/5. Capture export notifications.
        // Subscription responses can be multipart and may arrive split across reads,
        // so scan a rolling buffer instead of only the current chunk.
        $deviceId = $this->getDeviceIdForClient($client);
        if ($deviceId) {
            $cid = intval($client);
            $buf = (string)($this->lastResponses[$cid] ?? '');

            $this->captureExportFieldsFromBuffer($deviceId, $buf);

            // Prevent unbounded growth: keep last ~256KB which is enough to include JSON bodies.
            if (strlen($buf) > 262144) {
                $this->lastResponses[$cid] = substr($buf, -262144);
            }
        }
    }

    private function captureExportFieldsFromBuffer(string $deviceId, string $buf): void
    {
        // SID (attachUSB response body example: {"SID":123})
        if (preg_match('/"SID"\s*:\s*(\d+)/i', $buf, $m)) {
            $sid = (int)$m[1];
            $this->lastSidByDeviceId[$deviceId] = $sid;
            echo "Subscription SID captured for {$deviceId}: {$sid}\n";
        }

        // fileName
        if (preg_match('/"fileName"\s*:\s*"([^"]+)"/i', $buf, $m)) {
            $fileName = $m[1];
            $this->lastExportFileByDeviceId[$deviceId] = $fileName;
            echo "Export fileName captured for {$deviceId}: {$fileName}\n";
        }

        // exportPath (docs: exportPath: ["path1","path2",...])
        if (preg_match('/"exportPath"\s*:\s*(\[[\s\S]*?\]|"[^"]*")/i', $buf, $m)) {
            $rawVal = $m[1];
            $paths = null;
            $decoded = json_decode($rawVal, true);
            if (is_array($decoded)) {
                $paths = array_values(array_filter($decoded, fn ($p) => is_string($p) && $p !== ''));
            } elseif (is_string($decoded) && $decoded !== '') {
                $paths = [$decoded];
            }

            if ($paths && count($paths) > 0) {
                $this->lastExportPathsByDeviceId[$deviceId] = $paths;
                echo "Export exportPath captured for {$deviceId}: " . json_encode($paths) . "\n";
            }
        }
    }

    private function appendAttendanceEventLog($client, string $chunk): void
    {
        $deviceId = $this->getDeviceIdForClient($client) ?? 'unknown';
        $ts = date('Y-m-d H:i:s');
        $line = "==== {$ts} | {$deviceId} ====\n{$chunk}\n\n";

        $path = null;
        if (function_exists('storage_path')) {
            $path = storage_path('attendance_events.log');
        } else {
            $path = __DIR__ . '/../../../../storage/attendance_events.log';
        }

        @file_put_contents($path, $line, FILE_APPEND);
    }

    private function getDeviceIdForClient($client)
    {
        foreach ($this->commandMap as $deviceId => $sock) {
            if ($sock === $client) return $deviceId;
        }
        foreach ($this->subscriptionMap as $deviceId => $sock) {
            if ($sock === $client) return $deviceId;
        }
        return null;
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

    public function request($deviceId, $method, $uri, $data = "")
    {
        $isAttach = is_string($uri) && stripos($uri, 'AccessAppHelper/attachUSB') !== false;
        $map = $isAttach ? $this->commandMap : $this->commandMap;

        if (!isset($map[$deviceId])) {
            $connected = $this->connectedDeviceIds();
            return [
                "ok" => false,
                "error" => "Device not connected",
                "deviceId" => $deviceId,
                "connectedDeviceIds" => $connected,
            ];
        }

        $client = $map[$deviceId];
        if (!is_resource($client) || feof($client)) {
            $this->disconnectClient($deviceId, $client);
            return [
                "ok" => false,
                "error" => "Device socket is not available",
                "deviceId" => $deviceId,
            ];
        }
        $token = $this->tokenMap[intval($client)] ?? "";
        $clientId = intval($client);
        $this->lastResponses[$clientId] = '';

        if ($uri === '' || $uri === null) {
            return ["ok" => false, "error" => "Empty URI"];
        }
        if ($uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        // If we don't have a token yet, most CGI APIs will fail.
        if ($token === '' && !str_starts_with($uri, '/cgi-bin/api/global/login')) {
            return [
                "ok" => false,
                "error" => "No token available yet; wait for login to complete",
                "deviceId" => $deviceId,
            ];
        }

        // Build request
        $req = "$method $uri HTTP/1.1\r\n";
        if (!empty($token)) {
            $req .= "X-cgi-token: $token\r\n";
        }
        $req .= "Host: 127.0.0.1\r\n";
        // File downloads should not advertise JSON-only accept.
        if (stripos($uri, '/cgi-bin/FileManager.cgi') !== false) {
            $req .= "Accept: */*\r\n";
        } else {
            $req .= "Accept: application/json\r\n";
        }
        $req .= "Connection: keep-alive\r\n";
        if ($data !== "" && ($data[0] === '{' || $data[0] === '[')) {
            $req .= "Content-Type: application/json\r\n";
        } elseif ($data !== "") {
            // Common CGI expects form-urlencoded when not JSON
            $req .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $req .= "Content-Length: " . strlen($data) . "\r\n\r\n";
        $req .= $data;

        $bytes = @fwrite($client, $req);
        if ($bytes === false || $bytes === 0) {
            $this->disconnectClient($deviceId, $client);
            return [
                "ok" => false,
                "error" => "Device disconnected (broken pipe)",
                "deviceId" => $deviceId,
            ];
        }

        // attachUSB creates a streaming subscription on this socket.
        if ($isAttach || (is_string($uri) && stripos($uri, 'subscribe') !== false)) {
            $this->subscriptionMap[$deviceId] = $client;
            // Force future commands to use a different command socket (device will reconnect).
            if (($this->commandMap[$deviceId] ?? null) === $client) {
                unset($this->commandMap[$deviceId]);
            }
            return [
                "ok" => true,
                "raw" => "",
                "stream" => true,
                "sent" => $this->summarizeRequest($req),
            ];
        }

        // Read back the HTTP response (best-effort, timeout-based)
        $raw = $this->readHttpResponse($client, 8, 1024 * 1024 * 20);
        if (!is_string($raw) || strlen($raw) === 0) {
            // No response (socket closed or timed out). Drop mapping so caller can retry after reconnect.
            $this->disconnectClient($deviceId, $client);
            return [
                "ok" => false,
                "error" => "No response from device (connection closed or timed out)",
                "deviceId" => $deviceId,
            ];
        }
        return [
            "ok" => true,
            "raw" => $raw,
            "tokenUsed" => $token !== '' ? true : false,
            "sent" => $this->summarizeRequest($req),
        ];
    }

    private function disconnectClient(string $deviceId, $client): void
    {
        if (is_resource($client)) {
            $cid = intval($client);
            unset($this->tokenMap[$cid], $this->lastResponses[$cid], $this->lastHeartbeatAt[$cid]);
            @fclose($client);
        }

        if (isset($this->commandMap[$deviceId]) && $this->commandMap[$deviceId] === $client) {
            unset($this->commandMap[$deviceId]);
        }
        if (isset($this->subscriptionMap[$deviceId]) && $this->subscriptionMap[$deviceId] === $client) {
            unset($this->subscriptionMap[$deviceId]);
        }

        // Remove from clients list too
        foreach ($this->clients as $i => $c) {
            if ($c === $client) {
                unset($this->clients[$i]);
            }
        }
        $this->clients = array_values($this->clients);
    }

    private function summarizeRequest(string $req): string
    {
        // Return request line + headers only (no body)
        if (strpos($req, "\r\n\r\n") === false) return $req;
        return explode("\r\n\r\n", $req, 2)[0];
    }

    private function readHttpHeadersOnly($client, $timeoutSeconds = 2, $maxBytes = 65536)
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, $timeoutSeconds);

        $raw = '';
        while (strpos($raw, "\r\n\r\n") === false && strlen($raw) < $maxBytes) {
            $chunk = fread($client, 4096);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($client);
                if (!empty($meta['timed_out'])) break;
                break;
            }
            $raw .= $chunk;
        }

        // Switch back to non-blocking for main loop handling.
        stream_set_blocking($client, false);

        if (strpos($raw, "\r\n\r\n") === false) return $raw;
        return explode("\r\n\r\n", $raw, 2)[0];
    }

    // Intentionally no "read body prefix" helper:
    // subscription endpoints are streaming and are consumed by the main read loop.

    private function readHttpResponse($client, $timeoutSeconds = 8, $maxBytes = 20971520)
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, $timeoutSeconds);

        $raw = '';

        // Read headers
        while (strpos($raw, "\r\n\r\n") === false && strlen($raw) < $maxBytes) {
            $chunk = fread($client, 8192);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($client);
                if (!empty($meta['timed_out'])) break;
                break;
            }
            $raw .= $chunk;
        }

        if (strpos($raw, "\r\n\r\n") === false) {
            return $raw;
        }

        [$headerText, $body] = explode("\r\n\r\n", $raw, 2);
        $contentLength = null;
        $isChunked = false;

        foreach (explode("\r\n", $headerText) as $line) {
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int)trim(substr($line, strlen('Content-Length:')));
            }
            if (stripos($line, 'Transfer-Encoding:') === 0 && stripos($line, 'chunked') !== false) {
                $isChunked = true;
            }
        }

        // If we know body length, read exactly remaining bytes
        if ($contentLength !== null) {
            $remaining = $contentLength - strlen($body);
            while ($remaining > 0 && strlen($raw) < $maxBytes) {
                $chunk = fread($client, min(8192, $remaining));
                if ($chunk === '' || $chunk === false) {
                    $meta = stream_get_meta_data($client);
                    if (!empty($meta['timed_out'])) break;
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            return $headerText . "\r\n\r\n" . $body;
        }

        // Chunked or unknown length: read until timeout
        if ($isChunked) {
            while (strlen($raw) < $maxBytes) {
                $chunk = fread($client, 8192);
                if ($chunk === '' || $chunk === false) {
                    $meta = stream_get_meta_data($client);
                    if (!empty($meta['timed_out'])) break;
                    break;
                }
                $raw .= $chunk;
            }
            return $raw;
        }

        // Unknown length, non-chunked: best-effort additional reads until timeout
        while (strlen($raw) < $maxBytes) {
            $chunk = fread($client, 8192);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($client);
                if (!empty($meta['timed_out'])) break;
                break;
            }
            $raw .= $chunk;
        }

        // Switch back to non-blocking for main loop handling.
        stream_set_blocking($client, false);
        return $raw;
    }
}
