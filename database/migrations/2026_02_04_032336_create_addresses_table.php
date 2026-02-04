<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('region'); // Kita gunakan string karena nilai dari API dinamis
            $table->string('first_name_address');
            $table->string('last_name_address');
            $table->longText('address_location');
            $table->string('location_type')->nullable(); // Apartement, Suite, dll
            $table->string('city');
            $table->string('province'); // Dinamis dari API
            $table->string('postal_code');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
