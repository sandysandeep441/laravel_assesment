<?php

namespace App\Repositories;

use App\Models\Batch;

class BatchRepository
{
    public function createBatch($totalOrganizations)
    {
       $batch = Batch::create([
            'status' => 'pending',
            'total_organizations' => $totalOrganizations,
        ]);

        return $batch->getKey();
    }

    public function updateTotalOrganizations($batchId, $count)
    {
        Batch::find($batchId)->update(['total_organizations' => $count]);
    }

    public function incrementProcessed($batchId)
    {
        Batch::find($batchId)->increment('processed_organizations');
    }
}