<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'tsk_';

    public function up(): void {
        Schema::create('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'id', true);
            $table->string($this->_pre_col . 'code', 12)->unique();
            $table->string($this->_pre_col . 'title', 200);
            $table->mediumText($this->_pre_col . 'description')->nullable();

            $table->unsignedBigInteger($this->_pre_col . 'pro_id')->index()->nullable()->default(null);
            $table->foreign($this->_pre_col . 'pro_id', $this->_pre_col . 'pro_id_foreign')->references('pro_id')->on('projects')->cascadeOnDelete();

            $table->enum($this->_pre_col . 'category', array_keys(config('constants.TASK_CATEGORY')))->nullable()->default(null);
            $table->enum($this->_pre_col . 'priority', array_keys(config('constants.TASK_PRIORITY')))->default('medium')->index();
            $table->enum($this->_pre_col . 'status', array_keys(config('constants.TASK_STATUS')))->default('to_do')->index();

            $table->date($this->_pre_col . 'start_date')->nullable();
            $table->date($this->_pre_col . 'due_date')->nullable()->index();

            $table->decimal($this->_pre_col . 'est_hours', 8, 2)->default(0.00);
            $table->decimal($this->_pre_col . 'spent_hours', 8, 2)->default(0.00);

            $table->string($this->_pre_col . 'tags', 255)->nullable()->default(null);
            $table->unsignedInteger($this->_pre_col . 'progress')->default(0);

            $table->unsignedBigInteger($this->_pre_col . 'assigned_by')->index();
            $table->foreign($this->_pre_col . 'assigned_by', $this->_pre_col . 'assigned_by_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->dateTime($this->_pre_col . 'completed_at')->nullable();
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('tasks');
    }
};
