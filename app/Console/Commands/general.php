<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class general extends Command
{


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'general';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = new HttpServer('0.0.0.0',8004,'admin','Corepay@1');
        $service->start();
        $service->request('corepay','POST', 'cgi-bin/api/AccessAppHelper/attachUSB');
    }

    function generateLicense($company, $expiryDate, $secret)
    {
        // Convert expiry to timestamp
        $expiry = strtotime($expiryDate);

        // Short company fingerprint (first 6 chars of hash)
        $companyHash = substr(hash('sha256', $company), 0, 6);

        // Create raw payload
        $payload = $companyHash . $expiry;

        // Create signature using secret
        // We need 20 characters total.
        // companyHash: 6 chars
        // expiry: base_convert(expiry, 10, 36) is 6 chars (for dates around 2026)
        // signature: 8 chars
        // 6 + 6 + 8 = 20

        $expiryPart = strtoupper(base_convert($expiry, 10, 36));
        // Pad expiryPart to 6 chars if needed
        $expiryPart = str_pad($expiryPart, 6, '0', STR_PAD_LEFT);

        $signature = substr(hash_hmac('sha256', $payload, $secret), 0, 8);

        // Final key
        $key = strtoupper(base_convert(hexdec($companyHash), 10, 36));
        $key = str_pad($key, 6, '0', STR_PAD_LEFT);
        $key .= $expiryPart;
        $key .= strtoupper($signature);

        return $key;
    }

    function verifyLicense($key, $company, $secret)
    {
        if (strlen($key) !== 20) {
            echo "Invalid license key length\n";
            return false;
        }

        // Extract parts
        $companyPart = substr($key, 0, 6);
        $expiryPart = substr($key, 6, 6);
        $sigPart = substr($key, 12);

        // Convert expiry back
        $expiry = base_convert($expiryPart, 36, 10);

        // Rebuild expected payload
        $companyHash = substr(hash('sha256', $company), 0, 6);
        $payload = $companyHash . $expiry;

        $expectedSig = substr(hash_hmac('sha256', $payload, $secret), 0, 8);

        if ($sigPart !== strtoupper($expectedSig)) {
            echo "Signature verification failed\n";
            return false;
        }

        if (time() > $expiry) {
            echo "License expired\n";
            return false;
        }
        echo "License verified successfully\n";
        return true;
    }

}
