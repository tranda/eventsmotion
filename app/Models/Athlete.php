<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Athlete extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'club_id',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'photo',
        'qrcode',
        'category',
        'club_name',
        'certificate',
        'edbf_id',
        'document_no',
        'left_side',
        'right_side',
        'helm',
        'drummer',
        'official',
        'coach',
        'media',
        'supporter',
        'checked'
    ];
}
