<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'tsa_';

    public function up(): void {
        Schema::create('task_assignees', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'tsk_id')->index();
            $table->foreign($this->_pre_col . 'tsk_id', $this->_pre_col . 'tsk_id_foreign')->references('tsk_id')->on('tasks')->cascadeOnDelete();

            $table->unsignedBigInteger($this->_pre_col . 'usr_id')->index();
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->softDeletes($this->_pre_col . 'deleted_on');
            $table->unique([$this->_pre_col . 'tsk_id', $this->_pre_col . 'usr_id'], 'unique_task_assignee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('task_assignees');
    }
};
