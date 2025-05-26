<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('course_topics')->onDelete('cascade');
            $table->string('name');
            $table->string('video_link')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_lessons');
    }
};
