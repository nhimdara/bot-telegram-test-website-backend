<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('payway_qr_string')->nullable();
            $table->longText('payway_qr_image')->nullable();
            $table->text('payway_deeplink')->nullable();
            $table->text('payway_app_store')->nullable();
            $table->text('payway_play_store')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'payway_qr_string',
                'payway_qr_image',
                'payway_deeplink',
                'payway_app_store',
                'payway_play_store',
            ]);
        });
    }
};
