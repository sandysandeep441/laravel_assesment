<?php

namespace App\Services;

use App\Repositories\OrganizationRepository;
use App\Jobs\ProcessOrganizationOnboarding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkOnboardService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private OrganizationRepository $organizationRepository
    ) { }

    public function processBulkOnboarding(array $organizations): array
    {
        try {
            DB::beginTransaction();
            // Create batch record
            $batch = $this->organizationRepository->createBatch([
                'status' => 'pending',
                'total_organizations' => count($organizations),
                'processed_organizations' => 0,
            ]);

            // Prepare organization data for bulk insert
            $organizationsData = [];
            foreach ($organizations as $org) {
                $organizationsData[] = [
                    'name' => $org['name'],
                    'domain' => $org['domain'],
                    'contact_email' => $org['contact_email'] ?? null,
                    'status' => 'pending',
                    'batch_id' => $batch->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert organizations
            $insertedCount = $this->organizationRepository->bulkInsertOrganizations($organizationsData);

            // Get inserted organizations and dispatch jobs
            $organizationsToProcess = $this->organizationRepository->getOrganizationsByBatch($batch->id);
            
            foreach ($organizationsToProcess as $organization) {
                ProcessOrganizationOnboarding::dispatch($organization);
                
                Log::info('Organization onboarding job dispatched', [
                    'batch_id' => $batch->id,
                    'organization_id' => $organization->id,
                    'domain' => $organization->domain,
                    'status' => 'job_dispatched'
                ]);
            }

            DB::commit();

            Log::info('Bulk onboarding batch created', [
                'batch_id' => $batch->id,
                'total_organizations' => count($organizations),
                'inserted_count' => $insertedCount,
                'status' => 'batch_created'
            ]);

            return [
                'batch_id' => $batch->id,
                'total_organizations' => $organizationsToProcess->count(),
                'status' => 'processing'
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Bulk onboarding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'status' => 'batch_creation_failed'
            ]);

            throw $e;
        }
    }
}