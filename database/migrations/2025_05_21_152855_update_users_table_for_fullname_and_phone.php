<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add fullname
            $table->string('fullname')->after('id');

            // Make phone_number unique
            $table->string('phone_number')->unique()->change();

            // Drop old fields
            $table->dropColumn(['firstname', 'lastname', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Restore dropped columns
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->unique()->nullable();

            // Drop fullname
            $table->dropColumn('fullname');

            // Remove uniqueness from phone_number
            $table->string('phone_number')->change(); // Remove unique manually if needed
        });
    }
};

