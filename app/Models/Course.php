<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'title', 'category_id', 'course_level', 'max_student', 'is_public',
        'short_description', 'description', 'image', 'link_ebook', 'link_group',
        'slug', 'price', 'discount_type', 'discount'
    ];

    protected $casts = [
        'slug' => 'array',
        'is_public' => 'boolean',
        'course_level' => 'string',
        'discount_type' => 'string',
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
}
