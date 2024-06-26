<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('balance', 15, 2)->default(100);
            $table->decimal('link_obligation', 15, 2)->default(0);
            $table->decimal('link_income', 15, 2)->default(0); // Add this line
            $table->decimal('value', 15, 2)->default(100);
            $table->decimal('public_rate', 5, 2)->default(10);
            $table->decimal('auxiliary', 15, 2)->default(0);
            $table->integer('trigger')->default(0);
            $table->integer('trxCount')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
}

