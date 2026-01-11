<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrganizationOnboarding;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationOnboardingQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    public function test_bulk_onboard_queues_one_job_per_inserted_organization_on_expected_queue(): void
    {
        Queue::fake();

        $payload = [
            [
                'name' => 'Test Company 1',
                'domain' => 'queue-test-1.com',
                'contact_email' => 'contact1@queue-test-1.com',
            ],
            [
                'name' => 'Test Company 2',
                'domain' => 'queue-test-2.com',
                'contact_email' => 'contact2@queue-test-2.com',
            ],
        ];

        $response = $this->postJson('/api/bulk-onboard', $payload);

        $response->assertStatus(202);

        Queue::assertPushed(ProcessOrganizationOnboarding::class, 2);
        Queue::assertPushed(ProcessOrganizationOnboarding::class, function (ProcessOrganizationOnboarding $job) {
            return $job->queue === 'organization-onboarding';
        });
    }

    public function test_bulk_onboard_skips_duplicate_domains_and_only_queues_jobs_for_new_records(): void
    {
        Queue::fake();

        Organization::create([
            'name' => 'Existing Company',
            'domain' => 'existing-queue.com',
            'contact_email' => 'existing@existing-queue.com',
            'status' => 'completed',
        ]);

        $payload = [
            [
                'name' => 'Existing Company Duplicate',
                'domain' => 'existing-queue.com',
                'contact_email' => 'dup@existing-queue.com',
            ],
            [
                'name' => 'New Company',
                'domain' => 'new-queue.com',
                'contact_email' => 'contact@new-queue.com',
            ],
        ];

        $response = $this->postJson('/api/bulk-onboard', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseCount('organizations', 2);
        Queue::assertPushed(ProcessOrganizationOnboarding::class, 1);
    }
}
