<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessOrganizationOnboarding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [10, 30, 60];

    /**
     * The maximum number of seconds a job should run.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    /**
     * The unique ID for the job to ensure idempotency.
     */
    public string $uniqueId;

    protected int $organizationId;

    protected Organization $organization;

    /**
     * Create a new job instance.
     */
    public function __construct(int $organizationId)
    {
        $this->organizationId = $organizationId;
        $this->uniqueId = "org_onboarding_{$organizationId}";
        
        // Ensure job is unique per organization
        $this->onQueue('organization-onboarding');
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->uniqueId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->organization = Organization::findOrFail($this->organizationId);
        // Idempotency check: Refresh the organization to get latest state
        $this->organization->refresh();
        
        // Skip if already processed (idempotency)
        if ($this->organization->status !== 'pending') {
            Log::info('Organization onboarding skipped - not in pending status', [
                'organization_id' => $this->organization->id,
                'domain' => $this->organization->domain,
                'current_status' => $this->organization->status,
                'batch_id' => $this->organization->batch_id,
                'status' => 'job_skipped'
            ]);
            return;
        }

        // Update status to processing
        $this->updateOrganizationStatus('processing');

        Log::info('Organization onboarding started', [
            'organization_id' => $this->organization->id,
            'domain' => $this->organization->domain,
            'batch_id' => $this->organization->batch_id,
            'status' => 'processing_started'
        ]);

        try {
            // Simulate actual onboarding processing
            $this->performOnboarding();

            // Mark as completed
            $this->updateOrganizationStatus('completed', processed_at: now());
            
            // Update batch progress
            $this->updateBatchProgress();

            Log::info('Organization onboarding completed successfully', [
                'organization_id' => $this->organization->id,
                'domain' => $this->organization->domain,
                'batch_id' => $this->organization->batch_id,
                'status' => 'completed'
            ]);

        } catch (Exception $e) {
            // Mark as failed
            $this->updateOrganizationStatus('failed', failed_reason: $e->getMessage());
            
            Log::error('Organization onboarding failed', [
                'organization_id' => $this->organization->id,
                'domain' => $this->organization->domain,
                'batch_id' => $this->organization->batch_id,
                'error' => $e->getMessage(),
                'status' => 'failed'
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        $this->organization = Organization::find($this->organizationId) ?? new Organization(['id' => $this->organizationId]);
        // Mark as permanently failed after all retries exhausted
        $this->updateOrganizationStatus('failed', failed_reason: 
            "Job failed after {$this->tries} attempts: " . $exception->getMessage()
        );

        Log::error('Organization onboarding job failed permanently', [
            'organization_id' => $this->organization->id,
            'domain' => $this->organization->domain,
            'batch_id' => $this->organization->batch_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'status' => 'job_failed_permanently'
        ]);
    }

    /**
     * Perform the actual onboarding logic.
     */
    protected function performOnboarding(): void
    {
        // Simulate processing time - replace with actual business logic
        
        // Add your actual onboarding logic here:
        // - Send welcome emails
        // - Set up initial configurations
        // - Create related resources
        // - Call external APIs
        // etc.
        
        // For demonstration, we'll just simulate success
        return;
    }

    /**
     * Update organization status atomically.
     */
    protected function updateOrganizationStatus(
        string $status, 
        ?string $failed_reason = null, 
        ?\Carbon\Carbon $processed_at = null
    ): void {
        $updateData = ['status' => $status];
        
        if ($failed_reason) {
            $updateData['failed_reason'] = $failed_reason;
        }
        
        if ($processed_at) {
            $updateData['processed_at'] = $processed_at;
        }

        DB::table('organizations')
            ->where('id', $this->organization->id)
            ->update($updateData);
    }

    /**
     * Update batch progress.
     */
    protected function updateBatchProgress(): void
    {
        DB::table('batches')
            ->where('id', $this->organization->batch_id)
            ->increment('processed_organizations');
    }
}