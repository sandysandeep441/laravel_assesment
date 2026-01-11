<?php
namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Organization;
use App\Jobs\ProcessOrganizationOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BulkOnboardApiTest extends TestCase
{

    use RefreshDatabase, WithFaker;
    protected function setUp(): void
    {
        parent::setUp();
        
        // Add API headers to avoid 403
        $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }
    public function test_bulk_onboard_api_returns_batch_id_successfully(): void
    {
        Queue::fake();
        
        $organizations = [
            [
                "name" => "Test Company 1",
                "domain" => "test1.com",
                "contact_email" => "contact1@test1.com"
            ],
            [
                "name" => "Test Company 2",
                "domain" => "test2.com",
                "contact_email" => "contact2@test2.com"
            ]
        ];
        $response = $this->postJson('/api/bulk-onboard', $organizations);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'batch_id',
                'total_organizations',
                'status'
            ])
            ->assertJson([
                'message' => 'Bulk onboarding initiated successfully',
                'total_organizations' => 2,
                'status' => 'processing'
            ]);
        // Verify batch was created
        $this->assertDatabaseHas('batches', [
            'total_organizations' => 2,
            'processed_organizations' => 0,
            'status' => 'pending'
        ]);
        // Verify organizations were created
        $this->assertDatabaseCount('organizations', 2);
        
        // Verify jobs were dispatched
        Queue::assertPushed(ProcessOrganizationOnboarding::class, 2);
    }
    public function test_bulk_onboard_api_handles_validation_errors(): void
    {
        $invalidOrganizations = [
            [
                'name' => '', // Invalid: empty name
                'domain' => 'test.com',
                'contact_email' => 'invalid-email' // Invalid: not a valid email
            ],
            [
                'name' => 'Test Company',
                'domain' => '', // Invalid: empty domain
            ]
        ];
//         error_log('Test input data: '.json_encode($invalidOrganizations));
        $response = $this->postJson('/api/bulk-onboard', $invalidOrganizations);
//         error_log('Test response data: '.json_encode($response));
//         error_log('Test response code: '.json_encode($response->getStatusCode()));
        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'details'
            ])
            ->assertJson([
                'error' => 'Validation failed'
            ]);
    }
    public function test_bulk_onboard_api_limits_to_1000_records(): void
    {
        $organizations = [];
        for ($i = 0; $i < 1001; $i++) {
            $organizations[] = [
                'name' => "Test Company {$i}",
                'domain' => "test{$i}.com",
                'contact_email' => "contact{$i}@test{$i}.com"
            ];
        }
        $response = $this->postJson('/api/bulk-onboard', $organizations);
        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Maximum 1000 organizations allowed per request'
            ]);
        // Verify no batch or organizations were created
        $this->assertDatabaseCount('batches', 0);
        $this->assertDatabaseCount('organizations', 0);
    }
    public function test_bulk_onboard_api_handles_duplicate_domains(): void
    {
        Queue::fake();
        
        // Create an existing organization
        Organization::create([
            'name' => 'Existing Company',
            'domain' => 'existing.com',
            'contact_email' => 'existing@existing.com',
            'status' => 'completed'
        ]);
        $organizations = [
            [
                'name' => 'New Company 1',
                'domain' => 'new1.com',
                'contact_email' => 'contact1@new1.com'
            ],
            [
                'name' => 'New Company 2',
                'domain' => 'existing.com', // Duplicate domain
                'contact_email' => 'contact2@existing.com'
            ]
        ];
        $response = $this->postJson('/api/bulk-onboard', $organizations);
        $response->assertStatus(202);
        // Verify only 1 new organization was created (duplicate skipped)
        $this->assertDatabaseCount('organizations', 2); // 1 existing + 1 new
        
        // Verify only 1 job was dispatched (for the non-duplicate)
        Queue::assertPushed(ProcessOrganizationOnboarding::class, 1);
    }
    public function test_bulk_onboard_api_handles_empty_request(): void
    {
        $response = $this->postJson('/api/bulk-onboard', []);
        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Validation failed'
            ]);
        // Verify no batch or organizations were created
        $this->assertDatabaseCount('batches', 0);
        $this->assertDatabaseCount('organizations', 0);
    }
    public function test_bulk_onboard_api_creates_batch_with_correct_relationships(): void
    {
        Queue::fake();
        
        $organizations = [
            [
                'name' => 'Test Company',
                'domain' => 'test.com',
                'contact_email' => 'contact@test.com'
            ]
        ];
        $response = $this->postJson('/api/bulk-onboard', $organizations);
        $response->assertStatus(202);
        // Verify batch-organization relationship
        $organization = Organization::first();
        $batch = Batch::first();
        
        $this->assertEquals($batch->id, $organization->batch_id);
        $this->assertEquals('pending', $organization->status);
        $this->assertEquals(1, $batch->total_organizations);
        $this->assertEquals(0, $batch->processed_organizations);
    }
    public function test_bulk_onboard_api_handles_service_exceptions(): void
    {
        // Mock the service to throw an exception
        $this->mock(\App\Services\BulkOnboardService::class)
            ->shouldReceive('processBulkOnboarding')
            ->andThrow(new \Exception('Database connection failed'));
        $organizations = [
            [
                'name' => 'Test Company',
                'domain' => 'test.com',
                'contact_email' => 'contact@test.com'
            ]
        ];
        $response = $this->postJson('/api/bulk-onboard', $organizations);
        $response->assertStatus(500)
            ->assertJson([
                'error' => 'Failed to process bulk onboarding request'
            ]);
        // Verify no batch or organizations were created due to transaction rollback
        $this->assertDatabaseCount('batches', 0);
        $this->assertDatabaseCount('organizations', 0);
    }
    public function test_bulk_onboard_api_performance_with_large_dataset(): void
    {
        Queue::fake();
        
        // Test with 1000 organizations (maximum allowed)
        $organizations = [];
        for ($i = 0; $i < 1000; $i++) {
            $organizations[] = [ 
                'name' => "Test Company {$i}",
                'domain' => "test{$i}.com",
                'contact_email' => "contact{$i}@test{$i}.com"
            ];
        }
        $startTime = microtime(true);
        //error_log('Test input data: '.json_encode(count($organizations)));
        $response = $this->postJson('/api/bulk-onboard', $organizations);
        //error_log('Test response data: '.json_encode($response));
        //error_log('Test response code: '.json_encode($response->getStatusCode()));
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        //error_log('Test execution time: '.json_encode($executionTime));
        $response->assertStatus(202);
        // Should complete within reasonable time (less than 2 seconds for the API call)
        $this->assertLessThan(3.0, $executionTime);
        // Verify batch was created
        $this->assertDatabaseHas('batches', [
            'total_organizations' => 1000,
            'processed_organizations' => 0,
            'status' => 'pending'
        ]);
        // Verify organizations were created
        $this->assertDatabaseCount('organizations', 1000);
        
        // Verify jobs were dispatched
        Queue::assertPushed(ProcessOrganizationOnboarding::class, 1000);
    }
}
