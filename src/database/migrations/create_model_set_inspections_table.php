<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('model_set_inspections', function (Blueprint $table) {
            $table->id();
            $table->string('class')->comment("Classname of model");
            $table->integer('original_number')->comment("Original Number of model set which is inspected");
            $table->boolean('is_broken')->comment("Determine model set is broken");
            $table->unsignedBigInteger('root_id')->nullable()->comment("Primary ID of root model");
            $table->string('missing_ids')->nullable()->comment("IDs of models do not find their parent in set. String example: 2,35,6,10");
            $table->json('errors')->nullable();
            $table->boolean('is_resolved');
            $table->string('description')->nullable();
            $table->unsignedBigInteger('from_inspection_id')->nullable()->comment("Inspection ID which for current Inspection base on to resolve");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_set_inspections');
    }
};
