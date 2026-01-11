<?php

namespace Tests\Unit;

use App\Repositories\OrganizationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_insert_organizations_skips_duplicates_by_unique_domain_constraint(): void
    {
        $repo = new OrganizationRepository();

        $now = now();

        $data = [
            [
                'name' => 'Company A',
                'domain' => 'dup-repo.com',
                'contact_email' => 'a@dup-repo.com',
                'status' => 'pending',
                'batch_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Company A Duplicate',
                'domain' => 'dup-repo.com',
                'contact_email' => 'dup@dup-repo.com',
                'status' => 'pending',
                'batch_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Company B',
                'domain' => 'unique-repo.com',
                'contact_email' => 'b@unique-repo.com',
                'status' => 'pending',
                'batch_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $inserted = $repo->bulkInsertOrganizations($data);

        $this->assertIsInt($inserted);
        $this->assertDatabaseCount('organizations', 2);

        $this->assertDatabaseHas('organizations', [
            'domain' => 'dup-repo.com',
        ]);
        $this->assertDatabaseHas('organizations', [
            'domain' => 'unique-repo.com',
        ]);
    }
}
