<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkOnboardRequest;
use App\Services\BulkOnboardService;
use Illuminate\Http\JsonResponse;

class BulkOnboardController extends Controller
{
    public function __construct(
        private BulkOnboardService $bulkOnboardService
    ) {}

    public function store(BulkOnboardRequest $request): JsonResponse
    {
         $organizations = $request->all();
        // Limit to 1000 records per request as per requirements
        if (count($organizations) > 1000) {
            return response()->json([
                'error' => 'Maximum 1000 organizations allowed per request'
            ], 422);
        }

        try {
            $result = $this->bulkOnboardService->processBulkOnboarding($organizations);

            return response()->json([
                'message' => 'Bulk onboarding initiated successfully',
                'batch_id' => $result['batch_id'],
                'total_organizations' => $result['total_organizations'],
                'status' => $result['status']
            ], 202);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to process bulk onboarding request',
                'details' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}