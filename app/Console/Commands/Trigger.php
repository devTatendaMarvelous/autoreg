<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Trigger extends Command
{
    protected $signature = 'trigger
                            {--deviceId=auto : Device ID used by the connection server (auto picks the only connected device)}
                            {--controlPort=9001 : Local control port (must match general)}
                            {--exportType=TotalAttenInfo : Export type (e.g. ShiftInfo)}
                            {--method=1 : Export method from device docs}
                            {--start=2026-04 : Export start time (device format)}
                            {--end=2026-04 : Export end time (device format)}
                            {--output= : Output filename (defaults to exported fileName)}
                            {--no-subscribe : Skip attachUSB subscription step}
                            {--task-timeout=90 : Seconds to wait for export task completion}
                            {--debug : Print request/response details}
                            {--export-format=json : exportUSB body format (json|form)}';
    protected $description = 'Trigger corepay subscription, export, and download';

    private ?string $resolvedDeviceId = null;
    private bool $fileManagerListUnsupported = false;
    private bool $fileManagerListUnsupportedNotified = false;
    private string $exportLogPath = '';

    public function handle()
    {
        $this->exportLogPath = storage_path('log.txt');
        $readyTimeout = max(30, min(120, (int)$this->option('task-timeout')));

        if (!$this->option('no-subscribe')) {
            if (!$this->subscription()) {
                return Command::FAILURE;
            }

            // Some firmwares close the device connection after attachUSB.
            // Wait for it to reconnect and obtain a fresh token before exporting.
            if (!$this->waitForDeviceReady($readyTimeout)) {
                $this->error("Device did not become ready after attachUSB (no token). Try again or run with --no-subscribe.");
                $this->printConnectionStatus();
                return Command::FAILURE;
            }

        }

        // Ensure we have a token before export/download endpoints.
        if (!$this->waitForDeviceReady($readyTimeout)) {
            $this->error("No token available yet; wait for login to complete");
            $this->printConnectionStatus();
            return Command::FAILURE;
        }

        // Ensure the socket is actually responsive (not half-dead) before export.
        if (!$this->waitForDeviceResponsive($readyTimeout)) {
            $this->error("Device is not responding on the current connection; wait for reconnect and try again.");
            $this->printConnectionStatus();
            return Command::FAILURE;
        }

        $fileName = $this->export();
        if ($fileName === null) {
            return Command::FAILURE;
        }

        if (!$this->waitForDeviceReady($readyTimeout)) {
            $this->error("No token available yet; wait for login to complete");
            $this->printConnectionStatus();
            return Command::FAILURE;
        }

        if (!$this->waitForDeviceResponsive($readyTimeout)) {
            $this->error("Device is not responding on the current connection; wait for reconnect and try again.");
            $this->printConnectionStatus();
            return Command::FAILURE;
        }

        if (!$this->download($fileName)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printConnectionStatus(): void
    {
        $resp = $this->sendControl(['action' => 'status']);
        $raw = $resp['raw'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $this->line($raw);
        }
    }

    private function waitForDeviceResponsive(int $timeoutSeconds): bool

    {
        $deviceId = $this->getDeviceId();
        $end = time() + max(1, $timeoutSeconds);
        do {
            $resp = $this->sendControl([
                'action' => 'ping',
                'deviceId' => $deviceId,
            ]);
            if (($resp['ok'] ?? false) && is_string($resp['raw'] ?? null) && $resp['raw'] !== '') {
                return true;
            }
            usleep(500000);
        } while (time() < $end);
        return false;
    }

    private function subscription(): bool
    {
        $deviceId = $this->getDeviceId();

        $status = $this->sendControl([
            "deviceId" => $deviceId,
            "method"   => "POST",
            "uri"      => "/cgi-bin/api/AccessAppHelper/attachUSB",
            "data"     => "{}"
        ]);

        $this->info("attachUSB sent");
        if ($this->option('debug')) {
            $this->printDebugStatus($status);
        }

        if (!($status['ok'] ?? false)) {
            $maybe = $this->tryResolveDeviceIdFromError($deviceId, $status);
            if ($maybe !== null) {
                $this->resolvedDeviceId = $maybe;
                $this->warn("Using detected deviceId: {$maybe}");
                return $this->subscription();
            }
            $this->error($status['error'] ?? 'attachUSB failed');
            return false;
        }

        // Per docs, attachUSB returns SID. On some firmwares SID only shows up later in the
        // streaming events (each event includes "SID": ...). So treat SID as optional here.
        $sid = $this->pollLastSid($deviceId, 20);
        if ($sid !== null) {
            $this->appendToLog("attachUSB SID: {$sid}");
            $this->info("Subscription SID: {$sid}");
        } else {
            $this->warn("attachUSB SID not observed yet (may arrive in subsequent stream events).");
        }

        // If attachUSB returns Connection: close, the device will usually reconnect.
        $raw = (string)($status['raw'] ?? '');
        if ($raw !== '' && stripos($raw, "Connection: close") !== false) {
            $this->warn("attachUSB closed the connection; waiting for device to reconnect...");
        }

        return true;
    }

    private function extractSidFromRaw(?string $raw): ?int
    {
        $body = $this->extractHttpBody($raw);
        $json = json_decode($body ?? '', true);
        if (is_array($json) && isset($json['SID']) && is_numeric($json['SID'])) {
            return (int)$json['SID'];
        }
        if (is_string($body) && preg_match('/"SID"\s*:\s*(\d+)/', $body, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function pollLastSid(string $deviceId, int $timeoutSeconds): ?int
    {
        $end = time() + max(1, $timeoutSeconds);
        do {
            $resp = $this->sendControl([
                'action' => 'lastSid',
                'deviceId' => $deviceId,
            ]);
            $raw = $resp['raw'] ?? '';
            $decoded = json_decode(is_string($raw) ? $raw : '', true);
            if (is_array($decoded) && isset($decoded['sid']) && is_numeric($decoded['sid'])) {
                return (int)$decoded['sid'];
            }
            usleep(300000);
        } while (time() < $end);
        return null;
    }

    private function appendToLog(string $line): void
    {
        $path = storage_path('log.txt');
        @file_put_contents($path, "==== " . date('Y-m-d H:i:s') . " | {$line} ====\n", FILE_APPEND);
    }

    private function export(): ?string
    {
        $deviceId = $this->getDeviceId();
        $exportType = (string)$this->option('exportType');
        $method = (int)$this->option('method');
        $start = (string)$this->option('start');
        $end = (string)$this->option('end');
        $taskTimeout = (int)$this->option('task-timeout');

        [$startNorm, $endNorm] = $this->normalizeExportTimeRange($start, $end);

        $exportFormat = strtolower((string)$this->option('export-format'));

        $jsonPayload = json_encode([
            "exportType" => $exportType,
            "method"     => $method,
            "startTime"  => $startNorm,
            "endTime"    => $endNorm
        ]);
        $formPayload = $this->buildExportUsbFormBody($exportType, $method, $startNorm, $endNorm);

        // Although some docs/screens show form-urlencoded, this device has been returning taskID
        // reliably with JSON. Default is JSON; form is fallback.
        $primaryBody = $exportFormat === 'form' ? $formPayload : $jsonPayload;

        $status = $this->sendControl([
            "deviceId" => $deviceId,
            "method"   => "POST",
            "uri"      => "/cgi-bin/api/AccessAppHelper/exportUSB",
            "data"     => $primaryBody
        ]);

        $this->info("exportUSB sent");
        $this->printDebugStatus($status);
        $this->appendExportUsbLog('POST primary', $status);

        // If primary fails (HTTP 4xx/5xx) OR returns empty body, try secondary format (json <-> form)
        // before GET variants.
        $primaryBodyText = $this->extractHttpBody((string)($status['raw'] ?? '')) ?? '';
        if (!($status['ok'] ?? false) || trim($primaryBodyText) === '') {
            $secondaryBody = $exportFormat === 'form' ? $jsonPayload : $formPayload;
            $this->warn("exportUSB primary failed/empty; retrying with secondary format...");
            $status2 = $this->sendControl([
                "deviceId" => $deviceId,
                "method"   => "POST",
                "uri"      => "/cgi-bin/api/AccessAppHelper/exportUSB",
                "data"     => $secondaryBody
            ]);
            $this->printDebugStatus($status2);
            $this->appendExportUsbLog('POST secondary', $status2);

            $secondaryBodyText = $this->extractHttpBody((string)($status2['raw'] ?? '')) ?? '';
            if (($status2['ok'] ?? false) && trim($secondaryBodyText) !== '') {
                $status = $status2;
            } elseif (($status2['ok'] ?? false) && trim($secondaryBodyText) === '' && ($status['ok'] ?? false) && trim($primaryBodyText) !== '') {
                // keep primary if it had content
            } elseif (($status['ok'] ?? false) && trim($primaryBodyText) !== '') {
                // keep primary
            } else {
                // keep the "best" (prefer ok=true)
                $status = ($status2['ok'] ?? false) ? $status2 : $status;
            }
        }

        $status = $this->maybeRetryExportAsGet($deviceId, $exportType, $method, $startNorm, $endNorm, $status);
        if ($status === null) {
            return null;
        }

        // Try to parse fileName from HTTP body JSON (best-effort)
        $body = $this->extractHttpBody($status['raw'] ?? null);
        $json = json_decode($body ?? '', true);
        if (is_array($json)) {
            if (isset($json['taskID']) && is_numeric($json['taskID'])) {
                $taskId = (int)$json['taskID'];
                $this->warn("Export started (taskID={$taskId}). Waiting for completion...");
                $fileFromTask = $this->pollExportTaskForFileName($deviceId, $taskId, max(5, $taskTimeout));
                if (is_string($fileFromTask) && $fileFromTask !== '') {
                    if ($fileFromTask[0] !== '/') $fileFromTask = '/' . $fileFromTask;
                    $this->info("Export ready: {$fileFromTask}");
                    return $fileFromTask;
                }
            }

            $fileName = $json['fileName'] ?? $json['data']['fileName'] ?? null;
            if (is_string($fileName) && $fileName !== '') {
                // Some devices return absolute paths; normalize to start with /
                if ($fileName[0] !== '/') $fileName = '/' . $fileName;
                return $fileName;
            }
        }

        // If we got HTTP 200 but no body at all, we can't know what to download.
        if (is_string($status['raw'] ?? null) && trim((string)$body) === '') {
            $this->error("exportUSB returned an empty response body; cannot determine taskID/fileName.");
            return null;
        }

        // Per docs (4.21.3/4.21.4), we must wait for the attach subscription to push exportPath.
        // Prefer exportPath over fileName and do NOT guess.
        if (is_string($status['raw'] ?? null) && stripos($status['raw'], 'multipart/x-mixed-replace') !== false) {
            $this->warn("exportUSB is streaming; waiting for export notification with fileName...");
        } else {
            $this->warn("Waiting for export notification with exportPath...");
        }

        $paths = $this->pollLastExportPaths($deviceId, max(5, $taskTimeout > 0 ? $taskTimeout : 60));
        if (is_array($paths) && count($paths) > 0) {
            $p0 = $paths[0];
            if (is_string($p0) && $p0 !== '') {
                if ($p0[0] !== '/') $p0 = '/' . $p0;
                $this->info("Export ready (exportPath): {$p0}");
                return $p0;
            }
        }

        $this->error("exportUSB completed but exportPath was not received via subscription; cannot download safely.");
        return null;
    }

    private function normalizeExportTimeRange(string $start, string $end): array
    {
        // Docs/example for exportUSB show startTime/endTime like "2024-10" (monthly),
        // and these fields are optional. Some firmwares reject full timestamps here.
        // Rule:
        // - If user passes YYYY-MM, keep as-is
        // - If user passes YYYY-MM-DD, expand to 00:00:00 / 23:59:59
        $start = trim($start);
        $end = trim($end);

        $toStart = function (string $s): string {
            if (preg_match('/^\\d{4}-\\d{2}$/', $s)) return $s;
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $s)) return $s . ' 00:00:00';
            return $s;
        };
        $toEnd = function (string $s): string {
            if (preg_match('/^\\d{4}-\\d{2}$/', $s)) return $s;
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $s)) return $s . ' 23:59:59';
            return $s;
        };

        $startNorm = $toStart($start);
        $endNorm = $toEnd($end);

        return [$startNorm, $endNorm];
    }

    private function maybeRetryExportAsGet(string $deviceId, string $exportType, int $method, string $start, string $end, array $status): ?array
    {
        $raw = (string)($status['raw'] ?? '');
        $body = $this->extractHttpBody($raw) ?? '';
        $isOk = (bool)($status['ok'] ?? false);

        // If POST fails OR returns HTTP 200 with empty body, try alternate formats.
        if (!$isOk || trim($body) === '') {
            $this->warn("Retrying exportUSB with alternate formats...");

            $candidates = [
                // GET querystring (current)
                ['method' => 'GET', 'uri' => $this->buildExportUsbGetUri($exportType, $method, $start, $end), 'data' => ''],
                // GET with alternate param names
                ['method' => 'GET', 'uri' => $this->buildExportUsbGetUriAlt($exportType, $method, $start, $end), 'data' => ''],
                // POST with querystring + empty body (some firmwares reject JSON body)
                ['method' => 'POST', 'uri' => $this->buildExportUsbGetUri($exportType, $method, $start, $end), 'data' => ''],
                // POST form-urlencoded body
                ['method' => 'POST', 'uri' => '/cgi-bin/api/AccessAppHelper/exportUSB', 'data' => $this->buildExportUsbFormBody($exportType, $method, $start, $end)],
            ];

            $lastFail = null;
            foreach ($candidates as $c) {
                $status2 = $this->sendControl([
                    "deviceId" => $deviceId,
                    "method"   => $c['method'],
                    "uri"      => $c['uri'],
                    "data"     => $c['data'],
                ]);
                $this->printDebugStatus($status2);
                $this->appendExportUsbLog(($c['method'] ?? 'REQ') . ' ' . ($c['uri'] ?? ''), $status2);

                if (($status2['ok'] ?? false)) {
                    $b = $this->extractHttpBody((string)($status2['raw'] ?? '')) ?? '';
                    if (trim($b) !== '') {
                        return $status2;
                    }
                }

                $lastFail = $status2;
            }

            $maybe = $lastFail ? $this->tryResolveDeviceIdFromError($deviceId, $lastFail) : null;
            if ($maybe !== null) {
                $this->resolvedDeviceId = $maybe;
                $this->warn("Using detected deviceId: {$maybe}");
                return $this->maybeRetryExportAsGet($maybe, $exportType, $method, $start, $end, $lastFail);
            }

            $this->error(($lastFail['error'] ?? null) ?: ($status['error'] ?? null) ?: 'exportUSB failed');
            return null;
        }

        return $status;
    }

    private function download(?string $fileName): bool
    {
        $deviceId = $this->getDeviceId();
        $start = (string)$this->option('start');
        $exportType = (string)$this->option('exportType');
        $output = (string)($this->option('output') ?: '');
        $taskTimeout = (int)$this->option('task-timeout');

        $fileName = $fileName ?: ('/' . $exportType . '_' . $start . '.xml');
        if ($output === '') {
            $output = ltrim(basename($fileName), '/');
        }

        // Some firmwares store the export under a directory we can't list (FileManager list is 501).
        // Try common mount points and retry for a while (export may take time to finish).
        $base = ltrim(basename($fileName), '/');
        $pathCandidates = array_values(array_unique([
            $fileName,
            '/' . $base,
            '/mnt/usb/' . $base,
            '/mnt/' . $base,
            '/udisk/' . $base,
            '/usb/' . $base,
            $base,
        ]));

        $attempts = [];
        foreach ($pathCandidates as $p) {
            $attempts[] = "/cgi-bin/FileManager.cgi?action=downloadFile&fileName=" . $this->encodeFileNameForQuery($p);
            $attempts[] = "/cgi-bin/FileManager.cgi?action=downloadFile&filePath=" . $this->encodeFileNameForQuery($p);
        }

        $deadline = time() + max(5, $taskTimeout > 0 ? $taskTimeout : 30);
        $status = null;
        $usedUri = null;
        $fileData = null;

        while (time() < $deadline) {
            foreach ($attempts as $uri) {
                if ($this->option('debug')) {
                    $this->line("---- download attempt ----");
                    $this->line("GET {$uri}");
                }
                $status = $this->sendControl([
                    "deviceId" => $deviceId,
                    "method"   => "GET",
                    "uri"      => $uri,
                    "data"     => ""
                ]);
                if (!($status['ok'] ?? false)) {
                    if ($this->option('debug')) {
                        $this->line("---- download response ----");
                        $this->printDebugStatus($status);
                    }
                    continue;
                }

                $raw = $status['raw'] ?? null;
                $fileData = $this->extractHttpBody($raw);
                $usedUri = $uri;

                if ($this->option('debug')) {
                    $this->line("---- download response ----");
                    if (is_string($raw) && $raw !== '') {
                        $headers = strpos($raw, "\r\n\r\n") !== false ? explode("\r\n\r\n", $raw, 2)[0] : $raw;
                        $this->line($headers);
                    }
                    $this->line("Downloaded bytes: " . (is_string($fileData) ? strlen($fileData) : 0));
                }

                // Treat empty body as "not ready yet" and keep trying.
                if (is_string($fileData) && strlen($fileData) > 0) {
                    break 2;
                }
            }

            usleep(800000); // 0.8s before retrying all candidates
        }

        if (!($status['ok'] ?? false)) {
            $maybe = $this->tryResolveDeviceIdFromError($deviceId, $status);
            if ($maybe !== null) {
                $this->resolvedDeviceId = $maybe;
                $this->warn("Using detected deviceId: {$maybe}");
                return $this->download($fileName);
            }
            $this->error($status['error'] ?? 'download failed');
            $this->printDebugStatus($status);
            return false;
        }

        $raw = $status['raw'] ?? null;
        $fileData = $fileData ?? $this->extractHttpBody($raw);
        if ($fileData === null) {
            $this->error("Download failed or returned no body");
            return false;
        }

        if ($this->option('debug')) {
            $this->line("---- download response ----");
            if (is_string($raw) && $raw !== '') {
                $headers = strpos($raw, "\r\n\r\n") !== false ? explode("\r\n\r\n", $raw, 2)[0] : $raw;
                $this->line($headers);
            }
            $this->line("Downloaded bytes: " . strlen($fileData));
            if ($usedUri) {
                $this->line("Used download endpoint: {$usedUri}");
            }
        }

        // Don't write empty files (most likely not ready or wrong endpoint/filename)
        if (strlen($fileData) === 0) {
            $this->error("Downloaded 0 bytes (empty file).");
            return false;
        }

        $path = storage_path($output);
        file_put_contents($path, $fileData);

        $this->info("File downloaded: $path");
        return true;
    }

    private function encodeFileNameForQuery(string $fileName): string
    {
        // Device CGI expects slashes in file paths not to be percent-encoded.
        // rawurlencode() encodes "/" as "%2F", which leads to HTTP 400 on some firmwares.
        $encoded = rawurlencode($fileName);
        return str_replace('%2F', '/', $encoded);
    }

    private function getDeviceId(): string
    {
        return $this->resolvedDeviceId ?: (string)$this->option('deviceId');
    }

    private function tryResolveDeviceIdFromError(string $deviceId, array $status): ?string
    {
        if ($deviceId !== 'auto') return null;

        $raw = $status['raw'] ?? '';
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($decoded)) return null;

        $connected = $decoded['connectedDeviceIds'] ?? null;
        if (is_array($connected) && count($connected) === 1 && is_string($connected[0]) && $connected[0] !== '') {
            return $connected[0];
        }

        return null;
    }

    private function buildExportUsbGetUri(string $exportType, int $method, string $start, string $end): string
    {
        // Common CGI pattern: parameters in query string.
        // If your device expects different param names, we'll adjust based on its response body.
        $qs = http_build_query([
            'exportType' => $exportType,
            'method' => $method,
            'startTime' => $start,
            'endTime' => $end,
        ]);
        return "/cgi-bin/api/AccessAppHelper/exportUSB?{$qs}";
    }

    private function buildExportUsbGetUriAlt(string $exportType, int $method, string $start, string $end): string
    {
        $qs = http_build_query([
            'exportType' => $exportType,
            'exportMethod' => $method,
            'start' => $start,
            'end' => $end,
        ]);
        return "/cgi-bin/api/AccessAppHelper/exportUSB?{$qs}";
    }

    private function buildExportUsbFormBody(string $exportType, int $method, string $start, string $end): string
    {
        return http_build_query([
            'exportType' => $exportType,
            'method' => $method,
            'startTime' => $start,
            'endTime' => $end,
        ]);
    }

    private function sendControl(array $payload): array
    {
        $port = (int)$this->option('controlPort');
        $errno = 0;
        $errstr = '';
        // Use a short connect timeout so polling can't hang.
        $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
        if (!$fp) {
            $this->error("Failed to connect to connection server at 127.0.0.1:{$port}. Run `php artisan general` first.");
            return [
                'ok' => false,
                'error' => 'connection server not running',
                'raw' => '',
            ];
        }

        fwrite($fp, json_encode($payload) . "\n");
        stream_set_timeout($fp, 10);

        $raw = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) break;
                break;
            }
            $raw .= $chunk;
        }
        fclose($fp);

        // control server may wrap response in {"status": "..."}
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('status', $decoded)) {
            $status = $decoded['status'];
            if (is_array($status)) {
                // prefer returning raw HTTP if present
                if (isset($status['ok']) && $status['ok'] === false) {
                    return [
                        'ok' => false,
                        'error' => $status['error'] ?? 'request failed',
                        'raw' => json_encode($status),
                    ];
                }
                if (isset($status['raw']) && is_string($status['raw'])) {
                    return $this->normalizeHttpStatus([
                        'ok' => true,
                        'raw' => $status['raw'],
                        'sent' => $status['sent'] ?? null,
                    ]);
                }
                return $this->normalizeHttpStatus([
                    'ok' => true,
                    'raw' => json_encode($status),
                    'sent' => $status['sent'] ?? null,
                ]);
            }

            if ($status === false) {
                return ['ok' => false, 'error' => 'request failed', 'raw' => $raw];
            }

            if (is_string($status)) {
                return $this->normalizeHttpStatus(['ok' => true, 'raw' => $status]);
            }

            return $this->normalizeHttpStatus(['ok' => true, 'raw' => json_encode($status)]);
        }

        // Unwrapped response (treat as raw)
        $result = ['ok' => true, 'raw' => $raw];
        return $this->normalizeHttpStatus($result);
    }

    private function printDebugStatus(array $status): void
    {
        if ($this->option('debug')) {
            if (isset($status['sent']) && is_string($status['sent']) && $status['sent'] !== '') {
                $this->line("---- sent ----");
                $this->line($status['sent']);
            }
            if (isset($status['raw']) && is_string($status['raw']) && $status['raw'] !== '') {
                $this->line("---- raw ----");
                $this->line($status['raw']);
            } else {
                // Make sure we still see something useful when raw is empty.
                $this->line(json_encode($status));
            }
            return;
        }

        $this->line($status['raw'] ?? json_encode($status));
    }

    private function appendExportUsbLog(string $label, array $status): void
    {
        // Only log exportUSB-related calls (uri contains /AccessAppHelper/exportUSB)
        $sent = $status['sent'] ?? '';
        $raw = $status['raw'] ?? '';

        $entry = "==== " . date('Y-m-d H:i:s') . " | exportUSB | {$label} ====\n";
        if (is_string($sent) && $sent !== '') {
            $entry .= "-- sent --\n{$sent}\n";
        }
        if (is_string($raw) && $raw !== '') {
            $entry .= "-- raw --\n{$raw}\n";
        } else {
            $entry .= "-- raw --\n<empty>\n";
        }
        $entry .= "\n";

        // Best-effort write
        @file_put_contents($this->exportLogPath ?: storage_path('log.txt'), $entry, FILE_APPEND);
    }
    private function pollLastExportFileName(string $deviceId, int $timeoutSeconds): ?string
    {
        $end = time() + max(1, $timeoutSeconds);
        do {
            $resp = $this->sendControl([
                'action' => 'lastExport',
                'deviceId' => $deviceId,
            ]);
            $raw = $resp['raw'] ?? '';
            $decoded = json_decode(is_string($raw) ? $raw : '', true);
            if (is_array($decoded) && isset($decoded['fileName']) && is_string($decoded['fileName']) && $decoded['fileName'] !== '') {
                return $decoded['fileName'];
            }

            usleep(500000); // 0.5s
        } while (time() < $end);

        return null;
    }

    private function pollLastExportPaths(string $deviceId, int $timeoutSeconds): ?array
    {
        $end = time() + max(1, $timeoutSeconds);
        do {
            $resp = $this->sendControl([
                'action' => 'lastExportPaths',
                'deviceId' => $deviceId,
            ]);
            $raw = $resp['raw'] ?? '';
            $decoded = json_decode(is_string($raw) ? $raw : '', true);
            if (is_array($decoded)) {
                $paths = $decoded['exportPaths'] ?? null;
                if (is_array($paths) && count($paths) > 0) {
                    return $paths;
                }
            }

            usleep(500000);
        } while (time() < $end);

        return null;
    }

    private function pollExportTaskForFileName(string $deviceId, int $taskId, int $timeoutSeconds): ?string
    {
        $end = time() + max(1, $timeoutSeconds);
        $candidates = [
            "/cgi-bin/api/AccessAppHelper/exportUSB?taskID={$taskId}",
            "/cgi-bin/api/AccessAppHelper/exportUSB?taskId={$taskId}",
            "/cgi-bin/api/AccessAppHelper/getExportTask?taskID={$taskId}",
            "/cgi-bin/api/AccessAppHelper/getExportTask?taskId={$taskId}",
            "/cgi-bin/api/AccessAppHelper/exportTask?taskID={$taskId}",
            "/cgi-bin/api/AccessAppHelper/exportTask?taskId={$taskId}",
        ];

        do {
            foreach ($candidates as $uri) {
                $resp = $this->sendControl([
                    "deviceId" => $deviceId,
                    "method"   => "GET",
                    "uri"      => $uri,
                    "data"     => ""
                ]);

                if (!($resp['ok'] ?? false)) {
                    continue;
                }

                $raw = $resp['raw'] ?? '';
                $body = $this->extractHttpBody(is_string($raw) ? $raw : null);
                $json = json_decode($body ?? '', true);
                if (!is_array($json)) {
                    continue;
                }

                $fileName = $json['fileName'] ?? $json['data']['fileName'] ?? null;
                if (is_string($fileName) && $fileName !== '') {
                    return $fileName;
                }
            }

            $notify = $this->pollLastExportFileName($deviceId, 1);
            if (is_string($notify) && $notify !== '') {
                return $notify;
            }

            usleep(700000); // 0.7s
        } while (time() < $end);

        return null;
    }

    private function discoverExportedFileNameViaFileManager(
        string $deviceId,
        string $exportType,
        string $start,
        string $end,
        int $timeoutSeconds
    ): ?string {
        if ($this->fileManagerListUnsupported) {
            return null;
        }
        $paths = ['/', '/mnt', '/mnt/usb', '/udisk', '/usb'];
        $endAt = time() + max(1, $timeoutSeconds);

        $prefer = [
            strtolower($exportType),
            strtolower(str_replace('-', '', $start)),
            strtolower($start),
            strtolower(str_replace('-', '', $end)),
            strtolower($end),
        ];

        do {
            foreach ($paths as $path) {
                $files = $this->listFilesViaFileManager($deviceId, $path);
                if ($this->fileManagerListUnsupported) {
                    return null;
                }
                if (!$files) continue;

                // Prefer .xml that match exportType/start/end tokens; otherwise take the newest xml.
                $xml = array_values(array_filter($files, fn ($f) => is_string($f) && str_ends_with(strtolower($f), '.xml')));
                if (!$xml) continue;

                $scored = [];
                foreach ($xml as $f) {
                    $score = 0;
                    $lf = strtolower($f);
                    foreach ($prefer as $tok) {
                        if ($tok !== '' && strpos($lf, $tok) !== false) $score += 2;
                    }
                    // boost exact common pattern
                    if (strpos($lf, strtolower($exportType . '_' . $start)) !== false) $score += 5;
                    $scored[] = [$score, $f];
                }

                usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);
                $best = $scored[0][1] ?? null;
                if (is_string($best) && $best !== '') {
                    // Ensure it is a path from root if it isn't already
                    if ($best[0] !== '/' && $path !== '/' && $path !== '') {
                        $best = rtrim($path, '/') . '/' . ltrim($best, '/');
                    }
                    return $best;
                }
            }

            usleep(800000); // 0.8s
        } while (time() < $endAt);

        return null;
    }

    private function listFilesViaFileManager(string $deviceId, string $path): array
    {
        // Different firmwares expose different listing actions/params.
        $queries = [
            "/cgi-bin/FileManager.cgi?action=listDirectory&path=" . rawurlencode($path),
            "/cgi-bin/FileManager.cgi?action=listDirectory&dirName=" . rawurlencode($path),
            "/cgi-bin/FileManager.cgi?action=list&path=" . rawurlencode($path),
            "/cgi-bin/FileManager.cgi?action=list&dirName=" . rawurlencode($path),
            "/cgi-bin/FileManager.cgi?action=getFileList&path=" . rawurlencode($path),
            "/cgi-bin/FileManager.cgi?action=getFileList&dirName=" . rawurlencode($path),
        ];

        $lastError = null;
        $lastRaw = null;

        foreach ($queries as $uri) {
            $resp = $this->sendControl([
                "deviceId" => $deviceId,
                "method"   => "GET",
                "uri"      => $uri,
                "data"     => ""
            ]);
            if (!($resp['ok'] ?? false)) {
                $lastError = $resp['error'] ?? 'request failed';
                $lastRaw = $resp['raw'] ?? null;
                if (is_string($lastRaw) && stripos($lastRaw, '501 Not Implemented') !== false) {
                    $this->fileManagerListUnsupported = true;
                }
                continue;
            }

            $raw = $resp['raw'] ?? '';
            $lastRaw = $raw;
            $body = $this->extractHttpBody(is_string($raw) ? $raw : null) ?? '';

            $names = $this->parseFileNamesFromListResponse($body);
            if ($names) return $names;
        }

        // Useful for debugging: show the last FileManager error once per run.
        if ($lastError !== null) {
            if ($this->fileManagerListUnsupported) {
                if (!$this->fileManagerListUnsupportedNotified) {
                    $this->fileManagerListUnsupportedNotified = true;
                    $this->line("FileManager directory listing is not supported on this firmware (HTTP 501). Skipping discovery.");
                }
            } else {
                $this->line("FileManager list failed for path {$path}: {$lastError}");
                if (is_string($lastRaw) && $lastRaw !== '') {
                    $this->line($lastRaw);
                }
            }
        }

        return [];
    }

    private function parseFileNamesFromListResponse(string $body): array
    {
        $body = trim($body);
        if ($body === '') return [];

        // JSON format (best guess): { "fileList": [ { "name": "x.xml" }, ... ] }
        $json = json_decode($body, true);
        if (is_array($json)) {
            $list = $json['fileList'] ?? $json['files'] ?? $json['data']['fileList'] ?? null;
            if (is_array($list)) {
                $names = [];
                foreach ($list as $item) {
                    if (is_string($item)) $names[] = $item;
                    if (is_array($item) && isset($item['name']) && is_string($item['name'])) $names[] = $item['name'];
                    if (is_array($item) && isset($item['fileName']) && is_string($item['fileName'])) $names[] = $item['fileName'];
                }
                return array_values(array_unique($names));
            }
        }

        // Plain text fallback: extract anything that looks like a filename
        preg_match_all('/[A-Za-z0-9_\\-\\.]+\\.(xml|csv|txt)/i', $body, $m);
        $names = $m[0] ?? [];
        return array_values(array_unique($names));
    }

    private function waitForDeviceReady(int $timeoutSeconds): bool
    {
        $deviceId = $this->getDeviceId();
        $end = time() + max(1, $timeoutSeconds);

        do {
            $resp = $this->sendControl([
                'action' => 'status',
            ]);

            $raw = $resp['raw'] ?? '';
            $decoded = json_decode(is_string($raw) ? $raw : '', true);
            if (is_array($decoded)) {
                // raw is usually the inner status object from server: { ok, deviceIds, devices }
                if ($deviceId === 'auto') {
                    $deviceIds = $decoded['deviceIds'] ?? null;
                    if (is_array($deviceIds) && count($deviceIds) === 1 && is_string($deviceIds[0]) && $deviceIds[0] !== '') {
                        $this->resolvedDeviceId = $deviceIds[0];
                        $deviceId = $deviceIds[0];
                    }
                }

                $devices = $decoded['devices'] ?? null;
                if (is_array($devices) && isset($devices[$deviceId])) {
                    $hasToken = $devices[$deviceId]['hasToken'] ?? false;
                    $cmdConnected = $devices[$deviceId]['commandConnected'] ?? true;
                    if ($hasToken === true && $cmdConnected === true) {
                        return true;
                    }
                }
            }

            usleep(500000); // 0.5s
        } while (time() < $end);

        return false;
    }

    private function normalizeHttpStatus(array $result): array
    {
        $raw = $result['raw'] ?? '';
        if (!is_string($raw) || $raw === '') return $result;

        if (!preg_match('/^HTTP\\/\\d+(?:\\.\\d+)?\\s+(\\d{3})\\b/m', $raw, $m)) {
            return $result;
        }

        $code = (int)$m[1];
        if ($code >= 400) {
            return [
                'ok' => false,
                'error' => "HTTP {$code}",
                'raw' => $raw,
                'sent' => $result['sent'] ?? null,
            ];
        }

        return $result;
    }

    private function extractHttpBody(?string $raw): ?string
    {
        if ($raw === null) return null;
        if ($raw === '') return null;

        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) return $raw;

        return substr($raw, $pos + 4);
    }
}
