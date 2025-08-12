<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAnswer extends Model
{
    protected $fillable = ['user_id', 'course_id', 'question_id', 'answer_id', 'is_correct'];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function question()
    {
        return $this->belongsTo(CourseQuestion::class);
    }

    public function answer()
    {
        return $this->belongsTo(QuestionAnswer::class);
    }
}
