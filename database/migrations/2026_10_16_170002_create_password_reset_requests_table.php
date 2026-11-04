<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'prr_';

    public function up(): void {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->bigIncrements($this->_pre_col . 'id');
            $table->string($this->_pre_col . 'code', 12)->unique();
            $table->unsignedBigInteger($this->_pre_col . 'usr_id')->nullable()->comment('usr_id comes from users table.');
            $table->foreign($this->_pre_col . 'usr_id', $this->_pre_col . 'usr_id_foreign')->references('usr_id')->on('users')->nullOnDelete();

            $table->string($this->_pre_col . 'usr_name', 80);
            $table->string($this->_pre_col . 'usr_email', 160);

            $table->enum($this->_pre_col . 'status', array_keys(config('constants.PASSWORD_RESET_STATUS')))->default('pending');
            $table->enum($this->_pre_col . 'priority', array_keys(config('constants.PASSWORD_RESET_PRIORITY')))->default('high');

            $table->string($this->_pre_col . 'request_ip', 45)->default('');
            $table->unsignedInteger($this->_pre_col . 'request_count')->default(1);
            $table->dateTime($this->_pre_col . 'requested_at');

            $table->dateTime($this->_pre_col . 'last_requested_at')->nullable()->default(null);
            $table->dateTime($this->_pre_col . 'last_notified_at')->nullable()->default(null);

            $table->unsignedInteger($this->_pre_col . 'reminder_count')->default(0);

            $table->dateTime($this->_pre_col . 'opened_at')->nullable();
            $table->unsignedBigInteger($this->_pre_col . 'opened_by')->nullable()->comment('opened_by comes from users table.');
            $table->foreign($this->_pre_col . 'opened_by', $this->_pre_col . 'opened_by_foreign')->references('usr_id')->on('users')->nullOnDelete();

            $table->string($this->_pre_col . 'admin_action', 30)->default('');
            $table->dateTime($this->_pre_col . 'completed_at')->nullable();

            $table->unsignedBigInteger($this->_pre_col . 'completed_by')->nullable()->comment('completed_by comes from users table.');
            $table->foreign($this->_pre_col . 'completed_by', $this->_pre_col . 'completed_by_foreign')->references('usr_id')->on('users')->nullOnDelete();

            $table->string($this->_pre_col . 'note', 255)->default('');

            $table->index([$this->_pre_col . 'status', $this->_pre_col . 'last_notified_at'], $this->_pre_col . 'idx_pwr_status');
            $table->index([$this->_pre_col . 'usr_id', $this->_pre_col . 'status'], $this->_pre_col . 'idx_pwr_user');
            get_created_updated_by_db_column($table, $this->_pre_col);
        });
    }

    public function down(): void {
        Schema::dropIfExists('password_reset_requests');
    }
};
