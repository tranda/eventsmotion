<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisciplineProgression extends Model
{
    use HasFactory;

    protected $fillable = [
        'discipline_id',
        'race_plan_code',
    ];

    public function discipline()
    {
        return $this->belongsTo(Discipline::class);
    }
}
