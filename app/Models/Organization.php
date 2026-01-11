<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = ['name', 'domain', 'contact_email', 'status', 'batch_id', 'processed_at', 'failed_reason'];

    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }
}