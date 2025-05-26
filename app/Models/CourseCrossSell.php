<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseCrossSell extends Model
{
    protected $fillable = ['course_id', 'cross_course_id', 'note'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function crossCourse()
    {
        return $this->belongsTo(Course::class, 'cross_course_id');
    }
}
