<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('firebird')->table('widgets', function (Blueprint $table): void {
            $table->string('mnemo', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('firebird')->table('widgets', function (Blueprint $table): void {
            $table->dropColumn('mnemo');
        });
    }
};
