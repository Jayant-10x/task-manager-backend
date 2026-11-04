<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'tct_';

    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');

            $table->unsignedBigInteger($this->_pre_col . 'tsk_id')->index();
            $table->foreign($this->_pre_col . 'tsk_id', $this->_pre_col . 'tsk_id_foreign')->references('tsk_id')->on('tasks')->cascadeOnDelete();

            $table->unsignedBigInteger($this->_pre_col . 'who')->index();
            $table->foreign($this->_pre_col . 'who', $this->_pre_col . 'who_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->string($this->_pre_col . 'action', 200);
            $table->string($this->_pre_col . 'detail', 300)->default('');
            get_created_updated_by_db_column($table, $this->_pre_col, true);
            $table->index([$this->_pre_col . 'tsk_id', $this->_pre_col . 'id'], $this->_pre_col . 'idx_ta_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
