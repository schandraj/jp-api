<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'title',
        'category_id',
        'course_level',
        'max_student',
        'is_public',
        'short_description',
        'description',
        'image',
        'link_ebook',
        'link_group',
        'slug',
        'price',
        'discount_type',
        'discount',
        'status',
        'type',
        'start_date',
        'end_date',
        'poster',
        'duration',
        'studied',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'course_level' => 'string',
        'discount_type' => 'string',
        'status' => 'string',
        'type' => 'string',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'studied' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function topics()
    {
        return $this->hasMany(CourseTopic::class);
    }

    public function crossSells()
    {
        return $this->hasMany(CourseCrossSell::class);
    }

    public function benefits()
    {
        return $this->belongsToMany(Benefit::class, 'course_benefit');
    }

    public function questions()
    {
        return $this->hasMany(CourseQuestion::class);
    }
}
