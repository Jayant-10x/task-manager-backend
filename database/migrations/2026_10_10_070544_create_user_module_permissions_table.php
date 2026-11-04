<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'ump_';

    public function up(): void
    {
        Schema::create('user_module_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'usr_id')->nullable()->comment('User Id comes from users table.');
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->string($this->_pre_col . 'module_slug', 50);
            $table->boolean($this->_pre_col . 'can_view')->default(false);
            $table->boolean($this->_pre_col . 'can_add')->default(false);
            $table->boolean($this->_pre_col . 'can_edit')->default(false);
            $table->boolean($this->_pre_col . 'can_delete')->default(false);
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col);

            // A user has exactly one permission record per module
            $table->unique([$this->_pre_col . 'usr_id', $this->_pre_col . 'module_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_permissions');
    }
};
