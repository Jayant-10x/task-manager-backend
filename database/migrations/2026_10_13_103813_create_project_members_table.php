<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'pmb_';

    public function up(): void {
        Schema::create('project_members', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'pro_id');
            $table->unsignedBigInteger($this->_pre_col . 'usr_id');

            $table->foreign($this->_pre_col . 'pro_id', $this->_pre_col . 'pro_id_foreign')->references('pro_id')->on('projects')->cascadeOnDelete();
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->cascadeOnDelete();
            $table->softDeletes($this->_pre_col . 'deleted_on');
            $table->unique([$this->_pre_col . 'pro_id', $this->_pre_col . 'usr_id'], 'uniq_project_member');
        });
    }

    public function down(): void {
        Schema::dropIfExists('project_members');
    }
};
