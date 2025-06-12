<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseQuestion extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'course_id',
        'question',
        'discussion',
    ];

    /**
     * Get the course that owns the question.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the answers for the question.
     */
    public function answers()
    {
        return $this->hasMany(QuestionAnswer::class, 'question_id');
    }
}
