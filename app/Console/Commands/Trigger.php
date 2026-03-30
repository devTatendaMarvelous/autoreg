<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Trigger extends Command
{
    protected $signature = 'trigger';
    protected $description = 'Trigger corepay subscription, export, and download';

    public function handle()
    {
        $this->subscription();
        $this->export();
        $this->download();
    }

    private function subscription()
    {
        $fp = stream_socket_client("tcp://127.0.0.1:9001");
        if (!$fp) {
            $this->error("Failed to connect to server for subscription");
            return;
        }

        fwrite($fp, json_encode([
                "deviceId" => "corepay",
                "method"   => "POST",
                "uri"      => "cgi-bin/api/AccessAppHelper/attachUSB",
                "data"     => ""
            ]) . "\n");

        // read minimal response
        fread($fp, 1024);
        fclose($fp);

        $this->info("Subscription request sent");
    }

    private function export()
    {
        $fp = stream_socket_client("tcp://127.0.0.1:9001");
        if (!$fp) {
            $this->error("Failed to connect to server for export");
            return;
        }

        $data = json_encode([
            "exportType" => "AbnormalAttenInfo",
            "method"     => 1,
            "startTime"  => "2025-03",
            "endTime"    => "2025-03"
        ]);

        fwrite($fp, json_encode([
                "deviceId" => "corepay",
                "method"   => "GET",
                "uri"      => "cgi-bin/api/AccessAppHelper/exportUSB",
                "data"     => $data
            ]) . "\n");

        $data=fread($fp, 1024);
        fclose($fp);

        $this->info("Export request sent");

        $this->info($data);
    }

    private function download()
    {

        $fp = stream_socket_client("tcp://127.0.0.1:9001");
        if (!$fp) {
            $this->error("Failed to connect to server for download");
            return;
        }

        // Use GET for file download
        $uri = "/cgi-bin/FileManager.cgi?action=downloadFile&fileName=/ShiftInfo_2025-03.xml";
        fwrite($fp, json_encode([
                "deviceId" => "corepay",
                "method"   => "GET",
                "uri"      => $uri,
                "data"     => ""
            ]) . "\n");

        // read raw response from server
        $raw = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) break;
            $raw .= $chunk;
        }
        fclose($fp);

        // ⚡ Strip HTTP headers if they exist
        if (strpos($raw, "\r\n\r\n") !== false) {
            $parts = explode("\r\n\r\n", $raw, 2);
            $fileData = $parts[1] ?? '';
        } else {
            $fileData = $raw;
        }

        // save locally
        $path = storage_path('ShiftInfo_2025-03.xml');
        file_put_contents($path, $fileData);

        $this->info("File downloaded: $path");
    }
}
