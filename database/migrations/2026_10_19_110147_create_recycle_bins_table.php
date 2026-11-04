<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'rbs_';

    public function up(): void
    {
        Schema::create('recycle_bins', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');
            $table->string($this->_pre_col . 'module', 50);
            $table->string($this->_pre_col . 'original_id', 255);
            $table->string($this->_pre_col . 'label', 255);
            $table->longText($this->_pre_col . 'snapshot')->nullable();
            $table->unsignedInteger($this->_pre_col . 'file_count')->default(0);
            $table->json($this->_pre_col . 'drive_file_ids')->nullable();

            $table->unsignedBigInteger($this->_pre_col . 'deleted_by_id')->nullable()->comment('deleted_by_id comes from users table.');
            $table->foreign($this->_pre_col . 'deleted_by_id', $this->_pre_col . 'deleted_by_id_foreign')->references('usr_id')->on('users')->nullOnDelete();
            $table->string($this->_pre_col . 'deleted_by_name', 100)->nullable();

            $table->timestamp($this->_pre_col . 'deleted_at')->useCurrent();
            $table->string($this->_pre_col . 'deletion_reason', 255)->nullable();
            $table->timestamp($this->_pre_col . 'permanent_delete_at')->nullable();

            $table->enum($this->_pre_col . 'restore_status', ['deleted', 'restored', 'purged'])->default('deleted');
            $table->timestamp($this->_pre_col . 'restored_at')->nullable();
            $table->unsignedBigInteger($this->_pre_col . 'restored_by')->nullable()->comment('restored_by comes from users table.');
            $table->foreign($this->_pre_col . 'restored_by', $this->_pre_col . 'restored_by_foreign')->references('usr_id')->on('users')->nullOnDelete();

            $table->timestamp($this->_pre_col . 'purged_at')->nullable();
            $table->unsignedBigInteger($this->_pre_col . 'purged_by')->nullable()->comment('purged_by comes from users table.');
            $table->foreign($this->_pre_col . 'purged_by', $this->_pre_col . 'purged_by_foreign')->references('usr_id')->on('users')->nullOnDelete();
            $table->text($this->_pre_col . 'purge_error')->nullable();
            $table->unsignedInteger($this->_pre_col . 'purge_attempts')->default(0);
            $table->softDeletes($this->_pre_col . 'deleted_on');
            $table->index([$this->_pre_col . 'restore_status', $this->_pre_col . 'module']);
            $table->index($this->_pre_col . 'permanent_delete_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recycle_bins');
    }
};
