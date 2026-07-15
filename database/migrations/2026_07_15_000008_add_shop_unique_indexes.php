<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unique('telegram_id'));
        Schema::table('carts', fn (Blueprint $table) => $table->unique('user_id'));
        Schema::table('cart_items', fn (Blueprint $table) => $table->unique(['cart_id', 'product_id']));
    }

    public function down(): void
    {
        Schema::table('cart_items', fn (Blueprint $table) => $table->dropUnique(['cart_id', 'product_id']));
        Schema::table('carts', fn (Blueprint $table) => $table->dropUnique(['user_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['telegram_id']));
    }
};
