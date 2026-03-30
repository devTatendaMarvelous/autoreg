<?php

namespace App\Http\Controllers;

use App\Models\LoanApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LoanAssessmentController extends Controller
{
    public function assess()
    {
        // 1. Fetch the loan data from MySQL
//        $application = LoanApplication::findOrFail($id);

        // 2. Prepare the prompt for Llama
        $prompt = "Act as a credit risk officer. Analyze this loan application:
        Monthly Income: 1500
        Existing Debt: 300
        Requested Loan Amount: 800
        Employment Status: EMPLOYED

        Provide a response in JSON format:
        {
            'status': 'Approved' or 'Rejected',
            'risk_score': 1-10,
            'reasoning': 'short explanation'
        }";

        try {
            // 3. Call your local Ollama API
            $response = Http::timeout(60)->post('http://localhost:11434/api/generate', [
                'model' => 'llama3.2:1b', // Using 1b for faster CPU performance
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json' // Forces Llama to output valid JSON
            ]);

            if ($response->successful()) {
                $aiResult = json_decode($response->json('response'), true);

//                // 4. Update your database with the AI's decision
//                $application->update([
//                    'ai_status' => $aiResult['status'],
//                    'risk_score' => $aiResult['risk_score'],
//                    'ai_notes' => $aiResult['reasoning']
//                ]);

                return response()->json([
                    'message' => 'Assessment Complete',
                    'data' => $aiResult
                ]);
            }

        } catch (\Exception $e) {
            return response()->json(['error' => 'AI Service unavailable: ' . $e->getMessage()], 500);
        }
    }
}
