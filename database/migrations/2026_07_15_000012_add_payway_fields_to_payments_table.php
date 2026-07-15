<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('khqr_payload')->nullable()->change();
            $table->string('md5', 32)->nullable()->change();
            $table->string('provider_reference', 20)->nullable()->unique()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropColumn('provider_reference');
            $table->text('khqr_payload')->nullable(false)->change();
            $table->string('md5', 32)->nullable(false)->change();
        });
    }
};
