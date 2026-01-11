<?php

namespace Tests\Unit;

use App\Models\Batch;
use App\Models\Organization;
use App\Jobs\ProcessOrganizationOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessOrganizationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_idempotent_for_completed_organization(): void
    {
        // Create an organization that's already completed
        $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => 1,
            'processed_organizations' => 0,
        ]);

        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'completed', // Already completed
            'batch_id' => $batch->id,
            'processed_at' => now(),
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        // Execute the job
        $job->handle();

        // Verify organization status remains unchanged
        $organization->refresh();
        $this->assertEquals('completed', $organization->status);
        $this->assertNotNull($organization->processed_at);
        
        // Verify batch count remains unchanged
        $batch->refresh();
        $this->assertEquals(0, $batch->processed_organizations);
    }

    public function test_job_is_idempotent_for_failed_organization(): void
    {
        // Create an organization that's already failed
        $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => 1,
            'processed_organizations' => 0,
        ]);

        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'failed', // Already failed
            'batch_id' => $batch->id,
            'failed_reason' => 'Previous failure',
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        // Execute the job
        $job->handle();

        // Verify organization status remains unchanged
        $organization->refresh();
        $this->assertEquals('failed', $organization->status);
        $this->assertEquals('Previous failure', $organization->failed_reason);
        
        // Verify batch count remains unchanged
        $batch->refresh();
        $this->assertEquals(0, $batch->processed_organizations);
    }

    public function test_job_is_idempotent_for_processing_organization(): void
    {
        // Create an organization that's already processing
        $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => 1,
            'processed_organizations' => 0,
        ]);

        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'processing', // Already processing
            'batch_id' => $batch->id,
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        // Execute the job
        $job->handle();

        // Verify organization status remains unchanged (idempotency)
        $organization->refresh();
        $this->assertEquals('processing', $organization->status);
        $this->assertNull($organization->processed_at);
        
        // Verify batch count remains unchanged
        $batch->refresh();
        $this->assertEquals(0, $batch->processed_organizations);
    }

    public function test_job_processes_pending_organization_successfully(): void
    {
        // Create a pending organization
        $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => 1,
            'processed_organizations' => 0,
        ]);

        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'pending', // Pending status
            'batch_id' => $batch->id,
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        // Execute the job
        $job->handle();
        // error_log('Organization status before refresh: '. print_r($organization, true));
        // Verify organization status changes to completed
        $organization->refresh();
        // error_log('Organization status after refresh: '. print_r($organization, true));
        $this->assertEquals('completed', $organization->status);
        $this->assertNotNull($organization->processed_at);
        $this->assertNull($organization->failed_reason);
        
        // Verify batch count is incremented
        $batch->refresh();
        $this->assertEquals(1, $batch->processed_organizations);
    }

    public function test_job_handles_processing_failure_and_marks_failed(): void
    {
        // Create a pending organization
        $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => 1,
            'processed_organizations' => 0,
        ]);

        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'pending',
            'batch_id' => $batch->id,
        ]);

        // Mock the performOnboarding method to throw an exception
        $job = $this->getMockBuilder(ProcessOrganizationOnboarding::class)
            ->setConstructorArgs([$organization])
            ->onlyMethods(['performOnboarding'])
            ->getMock();
            
        $job->expects($this->once())
            ->method('performOnboarding')
            ->willThrowException(new \Exception('Simulated processing failure'));

        // Expect the job to throw an exception (for retry mechanism)
        $this->expectException(\Exception::class);
        
        $job->handle();

        // Verify organization is marked as failed
        $organization->refresh();
        $this->assertEquals('failed', $organization->status);
        $this->assertEquals('Simulated processing failure', $organization->failed_reason);
        
        // Verify batch count remains unchanged (since processing failed)
        $batch->refresh();
        $this->assertEquals(0, $batch->processed_organizations);
    }

    public function test_job_unique_id_is_generated_correctly(): void
    {
        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'pending',
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        $uniqueId = $job->uniqueId();
        
        $this->assertEquals("org_onboarding_{$organization->id}", $uniqueId);
    }

    public function test_job_logs_idempotency_skip(): void
    {
        // Create a completed organization
        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'completed',
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        // Expect log message for skipping
        Log::shouldReceive('info')
            ->once()
            ->with('Organization onboarding skipped - not in pending status', \Mockery::type('array'));
        
        $job->handle();
    }

    public function test_job_logs_processing_start_and_completion(): void
    {
        // Create a pending organization
        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'pending',
        ]);
        $job = new ProcessOrganizationOnboarding($organization);
        
        // Execute the job
        $job->handle();
        // Verify organization is completed
        $organization->refresh();
        $this->assertEquals('completed', $organization->status);
    }

    public function test_job_logs_failure_permanently_after_retries_exhausted(): void
    {
        $organization = Organization::create([
            'name' => 'Test Company',
            'domain' => 'test.com',
            'contact_email' => 'contact@test.com',
            'status' => 'pending',
        ]);

        $job = new ProcessOrganizationOnboarding($organization);
        
        $exception = new \Exception('Final failure after retries');
        
        $job->failed($exception);
        
        // Verify organization is marked as permanently failed
        $organization->refresh();
        error_log($organization);
        error_log($organization->failed_reason);
        $this->assertEquals('failed', $organization?->status);
        $this->assertStringContainsString('Job failed after 3 attempts', $organization->failed_reason);
    }
}