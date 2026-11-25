<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_sheets_oauth_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('client_id');
            $table->text('encrypted_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_sheets_oauth_tokens');
    }
};
