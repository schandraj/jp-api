<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopicLesson extends Model
{
    protected $fillable = ['topic_id', 'name', 'video_link', 'description', 'is_premium'];

    protected $casts = [
        'is_premium' => 'boolean',
    ];

    public function topic()
    {
        return $this->belongsTo(CourseTopic::class, 'topic_id');
    }

    public function watchedBy()
    {
        return $this->belongsToMany(User::class, 'watched_lessons');
    }
}
