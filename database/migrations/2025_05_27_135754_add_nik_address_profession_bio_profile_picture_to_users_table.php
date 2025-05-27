<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik', 16)->unique()->nullable()->after('phone_number');
            $table->text('address')->nullable()->after('nik');
            $table->string('profession')->nullable()->after('address');
            $table->text('bio')->nullable()->after('profession');
            $table->string('profile_picture')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nik', 'address', 'profession', 'bio', 'profile_picture']);
        });
    }
};
