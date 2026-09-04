<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extras_takeover_records', function (Blueprint $table) {
            $table->increments('id');

            $table->string('coordinate', 191)->default('');
            $table->string('format', 16)->default('');
            $table->string('action', 16);

            $table->longText('elements')->nullable();

            $table->timestamp('taken_at')->nullable();

            $table->index('coordinate');
        });
    }

    /** `extra:takeover --restore` reads these rows, so it has to run before the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('extras_takeover_records');
    }
};
