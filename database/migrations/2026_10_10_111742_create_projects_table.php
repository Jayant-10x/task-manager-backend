<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'pro_';

    public function up(): void {
        Schema::create('projects', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'id', true);
            $table->string($this->_pre_col . 'code', 12)->unique();
            $table->string($this->_pre_col . 'name', 120);
            $table->text($this->_pre_col . 'description')->nullable();

            $table->unsignedBigInteger($this->_pre_col . 'manager')->index();
            $table->foreign($this->_pre_col . 'manager', $this->_pre_col . 'manager_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->enum($this->_pre_col . 'status', array_keys(config('constants.PROJECT_STATUS')))->default('planning')->index();
            $table->tinyInteger($this->_pre_col . 'progress')->default(0);
            $table->date($this->_pre_col . 'start_date')->nullable();
            $table->date($this->_pre_col . 'due_date')->nullable();
            $table->decimal($this->_pre_col . 'budget', 14, 2)->default(0.00);
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('projects');
    }
};
