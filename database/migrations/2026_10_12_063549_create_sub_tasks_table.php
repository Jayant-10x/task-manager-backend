<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'stk_';

    public function up(): void {
        Schema::create('sub_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'id', true);
            $table->string($this->_pre_col . 'title', 200);

            $table->unsignedBigInteger($this->_pre_col . 'tsk_id')->index();
            $table->foreign($this->_pre_col . 'tsk_id', $this->_pre_col . 'tsk_id_foreign')->references('tsk_id')->on('tasks')->cascadeOnDelete();

            $table->boolean($this->_pre_col . 'done')->default(false);
            $table->unsignedInteger($this->_pre_col . 'sort_order')->default(0);
            $table->dateTime($this->_pre_col . 'completed_at')->nullable();
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col, true);
            $table->index([$this->_pre_col . 'tsk_id', $this->_pre_col . 'sort_order'], 'idx_subtasks_task');
        });
    }

    public function down(): void {
        Schema::dropIfExists('sub_tasks');
    }
};
