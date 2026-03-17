<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    public function register(Request $request)
    {
        Log::info("Device Registration Request", [
            'ip' => $request->ip(),
            'payload' => $request->all()
        ]);

        $payload = $request->all();
        $deviceId = $payload['DeviceID'] ?? null;
        $deviceIp = $request->ip();

        if ($deviceId) {
            $path = config_path('dahua.json');
            $config = file_exists($path) ? json_decode(file_get_contents($path), true) : ['devices' => []];

            $found = false;
            foreach ($config['devices'] as &$device) {
                if ($device['registration_id'] === $deviceId || $device['ip'] === $deviceIp) {
                    $device['ip'] = $deviceIp;
                    $device['registration_id'] = $deviceId;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $config['devices'][] = [
                    'ip' => $deviceIp,
                    'registration_id' => $deviceId,
                    'name' => $payload['Name'] ?? 'New Device'
                ];
            }

            file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));
        }

        return response()->json([
            "Status" => "OK",
            "Message" => "Device registered successfully"
        ])->header('Connection', 'keep-alive')
          ->header('Content-Length', strlen(json_encode([
              "Status" => "OK",
              "Message" => "Device registered successfully"
          ])));
    }
}
