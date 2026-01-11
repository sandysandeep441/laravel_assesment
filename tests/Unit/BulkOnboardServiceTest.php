<?php

namespace Tests\Unit;

use App\Jobs\ProcessOrganizationOnboarding;
use App\Repositories\OrganizationRepository;
use App\Services\BulkOnboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkOnboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_bulk_onboarding_creates_batch_inserts_organizations_and_dispatches_jobs(): void
    {
        Queue::fake();

        $service = new BulkOnboardService(new OrganizationRepository());

        $payload = [
            [
                'name' => 'Company 1',
                'domain' => 'service-test-1.com',
                'contact_email' => 'c1@service-test-1.com',
            ],
            [
                'name' => 'Company 2',
                'domain' => 'service-test-2.com',
                'contact_email' => 'c2@service-test-2.com',
            ],
        ];

        $result = $service->processBulkOnboarding($payload);

        $this->assertArrayHasKey('batch_id', $result);
        $this->assertSame(2, $result['total_organizations']);
        $this->assertSame('processing', $result['status']);

        $this->assertDatabaseHas('batches', [
            'id' => $result['batch_id'],
            'total_organizations' => 2,
            'processed_organizations' => 0,
        ]);

        $this->assertDatabaseCount('organizations', 2);

        Queue::assertPushed(ProcessOrganizationOnboarding::class, 2);
    }

    public function test_process_bulk_onboarding_skips_duplicates_and_only_dispatches_jobs_for_inserted_rows(): void
    {
        Queue::fake();

        $service = new BulkOnboardService(new OrganizationRepository());

        $payload = [
            [
                'name' => 'Company 1',
                'domain' => 'service-dup.com',
                'contact_email' => 'c1@service-dup.com',
            ],
            [
                'name' => 'Company 1 Duplicate',
                'domain' => 'service-dup.com',
                'contact_email' => 'dup@service-dup.com',
            ],
        ];

        $result = $service->processBulkOnboarding($payload);

        $this->assertSame(1, $result['total_organizations']);
        $this->assertDatabaseCount('organizations', 1);
        Queue::assertPushed(ProcessOrganizationOnboarding::class, 1);
    }

    public function test_process_bulk_onboarding_rolls_back_when_repository_throws(): void
    {
        $this->mock(OrganizationRepository::class)
            ->shouldReceive('createBatch')
            ->andThrow(new \Exception('Simulated repository failure'));

        $service = app(BulkOnboardService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Simulated repository failure');

        try {
            $service->processBulkOnboarding([
                [
                    'name' => 'Company 1',
                    'domain' => 'service-rollback.com',
                    'contact_email' => 'c1@service-rollback.com',
                ],
            ]);
        } finally {
            $this->assertDatabaseCount('batches', 0);
            $this->assertDatabaseCount('organizations', 0);
        }
    }
}
