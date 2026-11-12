<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * The MongoDB connection that holds the e-Panduan programme data.
     *
     * @var string
     */
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('programmes', function (Blueprint $collection) {
            $collection->unique('programme_code');
        });

        Schema::connection($this->connection)->create('eligibility_requirements', function (Blueprint $collection) {
            $collection->index(['level', 'target_category', 'is_offered']);
            $collection->index('programme_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('eligibility_requirements');
        Schema::connection($this->connection)->dropIfExists('programmes');
    }
};
