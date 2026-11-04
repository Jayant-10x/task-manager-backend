<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'atc_';

    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');
            $table->unsignedBigInteger($this->_pre_col . 'tsk_id')->index();
            $table->foreign($this->_pre_col . 'tsk_id', $this->_pre_col . 'tsk_id_foreign')->references('tsk_id')->on('tasks')->cascadeOnDelete();

            $table->unsignedBigInteger($this->_pre_col . 'pro_id')->index()->nullable()->default(null);
            $table->foreign($this->_pre_col . 'pro_id', $this->_pre_col . 'pro_id_foreign')->references('pro_id')->on('projects')->cascadeOnDelete();

            $table->string($this->_pre_col . 'file_name', 255);
            $table->string($this->_pre_col . 'stored_as', 255);
            $table->string($this->_pre_col . 'mime', 100);
            $table->unsignedInteger($this->_pre_col . 'size_kb')->default(0);

            $table->unsignedBigInteger($this->_pre_col . 'uploaded_by')->index();
            $table->foreign($this->_pre_col . 'uploaded_by', $this->_pre_col . 'uploaded_by_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->dateTime($this->_pre_col . 'created_at');
            $table->string($this->_pre_col . 'storage', 10)->default('local');
            $table->string($this->_pre_col . 'drive_file_id', 200)->nullable()->index('idx_att_drive_file');
            $table->string($this->_pre_col . 'drive_url', 300)->nullable();
            $table->softDeletes($this->_pre_col . 'deleted_on');
            $table->index($this->_pre_col . 'tsk_id', 'idx_att_task');
            $table->index($this->_pre_col . 'pro_id', 'idx_att_project');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
