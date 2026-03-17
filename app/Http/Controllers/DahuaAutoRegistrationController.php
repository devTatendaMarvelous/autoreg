<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Employee;
use Symfony\Component\HttpFoundation\Response;

class DahuaAutoRegistrationController extends Controller
{
    /**
     * 4.19.1 Auto Connection Device Interface
     * The device pushes this message regularly before registration is successful[cite: 2127].
     */
    public function connect(Request $request)
    {
        // Log for your terminal monitoring
        \Log::info("Dahua CGI: Connect attempt from " . $request->ip());

        // 4.19.1: Return 200 OK with NO body [cite: 2168, 2178]
        $response = new Response();
        $response->setStatusCode(200);
        $response->setContent('');

        // Manually set headers.
        // This often forces the underlying server to respect our connection preference.
        $response->headers->set('Connection', 'keep-alive', true); // 'true' replaces existing
        $response->headers->set('Content-Length', '0');
        $response->headers->set('Content-Type', 'text/plain');

        return $response;
    }
    /**
     * 4.19.2 Login Interface
     * After successful login, the device returns the token used for subsequent authentication[cite: 2185].
     */
    public function login(Request $request)
    {
        Log::info("Dahua CGI Login Request", [
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        // Generate a token (22 chars typical in Dahua docs)[cite: 2186, 2215].
        $token = Str::random(22);

        // Cache token for the Heartbeat interface validation[cite: 2218].
        Cache::put("dahua_token_" . $request->ip(), $token, now()->addMinutes(60));

        // The response must be a JSON object containing the Token key[cite: 2214, 2215].
        return response()->json([
            "Token" => $token,
        ])->header('Connection', 'keep-alive')
          ->header('Content-Length', strlen(json_encode(["Token" => $token])));
    }

    /**
     * 4.19.3 Heartbeat Interface
     * The device sends heartbeat messages regularly (30s default)[cite: 2218].
     */
    public function keepAlive(Request $request)
    {
        // Token is sent in the X-cgi-token HTTP header[cite: 2186, 2221].
        $token = $request->header('X-cgi-token');
        $storedToken = Cache::get("dahua_token_" . $request->ip());

        if ($token && $token === $storedToken) {
            // Extend the token lifetime upon successful heartbeat[cite: 2219].
            Cache::put("dahua_token_" . $request->ip(), $token, now()->addMinutes(60));

            // Response is 200 OK with no body[cite: 2223].
            return response('', 200)
                ->header('Connection', 'keep-alive')
                ->header('Content-Length', 0);
        }

        Log::warning("Dahua Keep-Alive Failed: Invalid/Expired Token", [
            'ip' => $request->ip(),
            'received' => $token
        ]);

        return response('', 401)->header('Connection', 'keep-alive');
    }

    /**
     * 4.20.1 Attendance Export Notification
     * Step 3: Device sends progress events to the subscription caller[cite: 2249].
     */
    public function attachUSBNotification(Request $request)
    {
        $payload = $request->json()->all();

        Log::info("Dahua Attendance Export Event", [
            'ip' => $request->ip(),
            'state' => $payload['state'] ?? 'unknown',
            'progress' => $payload['progress'] ?? 0
        ]);

        // If progress is 100, the exportPath is provided for download[cite: 2252, 2253].
        if (isset($payload['progress']) && $payload['progress'] == 100) {
            Log::info("Dahua File Ready for Download", [
                'paths' => $payload['exportPath'] ?? []
            ]);
        }

        return response('', 200)->header('Connection', 'keep-alive');
    }

    /**
     * General Event Receiver
     * Used for person information report or card swipes if configured[cite: 2118].
     */
    public function receive(Request $request)
    {
        $payload = $request->all();

        Log::info("Dahua Data Push Received", [
            'ip' => $request->ip(),
            'payload' => $payload
        ]);

        // Process logic for Employee/Attendance mapping
        return response()->json(["status" => "success"])
            ->header('Connection', 'keep-alive');
    }
}
