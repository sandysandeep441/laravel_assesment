<?php

namespace App\Repositories;

use App\Models\Organization;
use App\Models\Batch;
use Illuminate\Support\Facades\DB;

class OrganizationRepository
{
    /**
     * Create a new batch record.
     */
    public function createBatch(array $data): Batch
    {
        return Batch::create($data);
    }

    /**
     * Bulk insert organizations with chunking.
     */
    public function bulkInsertOrganizations(array $organizationsData): int
    {
        $chunks = array_chunk($organizationsData, 500);
        $totalInserted = 0;

        foreach ($chunks as $chunk) {
            $inserted = DB::table('organizations')->insertOrIgnore($chunk);
            $totalInserted += $inserted;
        }

        return $totalInserted;
    }

    /**
     * Get organizations by batch ID.
     */
    public function getOrganizationsByBatch(int $batchId): \Illuminate\Database\Eloquent\Collection
    {
        return Organization::where('batch_id', $batchId)->get();
    }
}