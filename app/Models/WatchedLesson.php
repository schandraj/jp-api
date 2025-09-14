<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WatchedLesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'topic_lesson_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'watched_at' => 'datetime',
    ];

    /**
     * Get the user that watched the lesson.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the lesson that was watched.
     */
    public function lesson()
    {
        return $this->belongsTo(TopicLesson::class);
    }
}
