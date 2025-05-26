<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->enum('course_level', ['BEGINNER', 'INTERMEDIATE', 'ADVANCE', 'EXPERT']);
            $table->integer('max_student')->unsigned();
            $table->boolean('is_public')->default(true);
            $table->text('short_description');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('link_ebook')->nullable();
            $table->string('link_group')->nullable();
            $table->json('slug')->nullable();
            $table->decimal('price', 10, 2);
            $table->enum('discount_type', ['PERCENTAGE', 'NOMINAL'])->nullable();
            $table->decimal('discount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
