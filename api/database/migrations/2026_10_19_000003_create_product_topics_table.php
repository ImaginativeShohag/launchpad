<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_topics', function (Blueprint $table) {
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            // No cascade: a topic that still has products cannot be deleted.
            $table->foreignUlid('topic_id')->constrained()->restrictOnDelete();

            $table->primary(['product_id', 'topic_id']);
            $table->index('topic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_topics');
    }
};
