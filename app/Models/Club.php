<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'name',
        'country',
        'active',
        'req_adel'
    ];

    public function teams()
    {
        return $this->hasMany(Team::class);
    }
}
