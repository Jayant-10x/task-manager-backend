<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'not_';

    public function up (): void {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');

            $table->unsignedBigInteger($this->_pre_col . 'usr_id')->index()->comment('user id for who notification hit.');
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->string($this->_pre_col . 'kind', 60);

            $table->string($this->_pre_col . 'title', 160);
            $table->string($this->_pre_col . 'body', 300)->default('');
            /*$table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('task_code', 12)->default('');*/
            $table->enum($this->_pre_col . 'priority', ['high', 'medium', 'low'])->default('medium');
            $table->json($this->_pre_col . 'meta_info')->nullable();
            $table->string($this->_pre_col . 'actor', 80)->default('');
            $table->boolean($this->_pre_col . 'is_read')->default(false);
            get_created_updated_by_db_column($table, $this->_pre_col, true);
            $table->index([$this->_pre_col . 'usr_id', $this->_pre_col . 'is_read'], 'idx_notif_user_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down (): void {
        Schema::dropIfExists('notifications');
    }
};
