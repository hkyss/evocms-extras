<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('extras_install_records', function (Blueprint $table) {
            $table->increments('id');

            $table->string('coordinate', 191)->unique();
            $table->string('format', 16);
            $table->string('version', 191)->default('');
            $table->string('title', 191)->default('');

            $table->longText('files')->nullable();
            $table->longText('backups')->nullable();
            $table->longText('elements')->nullable();
            $table->longText('sql_hashes')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras_install_records');
    }
};
