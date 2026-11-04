<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'tsc_';

    public function up(): void {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');

            $table->unsignedBigInteger($this->_pre_col . 'tsk_id')->index();
            $table->foreign($this->_pre_col . 'tsk_id', $this->_pre_col . 'tsk_id_foreign')->references('tsk_id')->on('tasks')->cascadeOnDelete();

            $table->unsignedBigInteger($this->_pre_col . 'author')->index();
            $table->foreign($this->_pre_col . 'author', $this->_pre_col . 'author_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->text($this->_pre_col . 'comment');
            $table->json($this->_pre_col . 'mentions')->nullable()->default(null)->comment('mentions are the user ids');
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col, true);
        });
    }

    public function down(): void {
        Schema::dropIfExists('task_comments');
    }
};
