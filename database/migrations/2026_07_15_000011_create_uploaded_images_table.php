<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mime_type', 50);
            $table->unsignedInteger('size');
            $table->binary('data');
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('uploaded_image_id')->nullable()->after('image_url')
                ->constrained('uploaded_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('uploaded_image_id'));
        Schema::dropIfExists('uploaded_images');
    }
};
