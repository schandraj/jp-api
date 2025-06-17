<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Benefit extends Model
{
    protected $fillable = ['icon', 'name'];

    /**
     * Get the courses that have this benefit.
     */
    public function courses()
    {
        return $this->belongsToMany(Course::class, 'course_benefit');
    }
}
