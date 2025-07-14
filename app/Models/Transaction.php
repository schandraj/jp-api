<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $fillable = [
        'order_id',
        'course_id',
        'email',
        'total',
        'status',
        'type',
        'notes'
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'status' => 'string',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
