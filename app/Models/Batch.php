<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Organization;

class Batch extends Model
{
    protected $fillable = ['status', 'total_organizations', 'processed_organizations'];

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }   
}