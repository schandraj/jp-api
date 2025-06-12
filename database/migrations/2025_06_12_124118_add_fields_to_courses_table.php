<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->enum('type', ['Course', 'Live_Teaching', 'CBT'])->default('Course')->after('status');
            $table->dateTime('start_date')->nullable()->after('type');
            $table->dateTime('end_date')->nullable()->after('start_date');
            $table->string('poster')->nullable()->after('image');
            $table->unsignedInteger('duration')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['type', 'start_date', 'end_date', 'poster', 'duration']);
        });
    }
};
