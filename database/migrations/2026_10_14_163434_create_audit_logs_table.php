<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'alg_';

    public function up(): void {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');

            // Who performed the action?
            $table->unsignedBigInteger($this->_pre_col . 'usr_id')->nullable()->comment('usr_id comes from users table.');
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->nullOnDelete();

            $table->string($this->_pre_col . 'actor_name')->nullable();
            $table->string($this->_pre_col . 'actor_email')->nullable();
            $table->string($this->_pre_col . 'actor_role')->nullable();

            // What happened?
            $table->string($this->_pre_col . 'action', 100)->index();
            $table->string($this->_pre_col . 'module', 100)->index();
            $table->string($this->_pre_col . 'record_id', 100)->nullable()->index();

            // What changed?
            $table->json($this->_pre_col . 'old_values')->nullable();
            $table->json($this->_pre_col . 'new_values')->nullable();
            $table->text($this->_pre_col . 'description')->nullable();

            // Request details
            $table->string($this->_pre_col . 'ip_address', 45)->nullable();
            $table->text($this->_pre_col . 'user_agent')->nullable();

            get_created_updated_by_db_column($table, $this->_pre_col, true);
            $table->index([$this->_pre_col . 'module', $this->_pre_col . 'record_id', $this->_pre_col . 'updated_on']);
            $table->index([$this->_pre_col . 'usr_id', $this->_pre_col . 'updated_on']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('audit_logs');
    }
};
