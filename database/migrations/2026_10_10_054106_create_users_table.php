<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'usr_';

    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->bigInteger($this->_pre_col . 'id')->unsigned()->autoIncrement();
            $table->string($this->_pre_col . 'name', 80)->unique();
            $table->string($this->_pre_col . 'email', 160)->unique();
            $table->string($this->_pre_col . 'phone', 30)->default('');
            $table->enum($this->_pre_col . 'role', array_keys(config('constants.ROLES')))->default('employee')->index();
            $table->string($this->_pre_col . 'designation', 60)->default('');
            $table->string($this->_pre_col . 'dept', 40)->default('General');

            $table->unsignedBigInteger($this->_pre_col . 'reporting_to')->nullable()->comment('reporting_to comes from users table.');
            $table->foreign($this->_pre_col . 'reporting_to', $this->_pre_col . 'reporting_to_foreign')->references('usr_id')->on('users')->cascadeOnDelete();

            $table->string($this->_pre_col . 'city', 60)->default('');
            $table->boolean($this->_pre_col . 'status')->default(1)->comment('1 - Active and 0 - Inactive')->index();
            $table->boolean($this->_pre_col . 'online')->default(false);
            $table->dateTime($this->_pre_col . 'last_seen')->nullable();
            $table->date($this->_pre_col . 'joined')->nullable();
            $table->string($this->_pre_col . 'password', 255);
            $table->string($this->_pre_col . 'token', 128)->nullable();
            $table->dateTime($this->_pre_col . 'token_at')->nullable();
            $table->char($this->_pre_col . 'reset_hash', 64)->nullable()->index();
            $table->dateTime($this->_pre_col . 'reset_expires')->nullable();
            $table->boolean($this->_pre_col . 'reset_used')->default(false);
            $table->unsignedInteger($this->_pre_col . 'fail_count')->default(0);
            $table->dateTime($this->_pre_col . 'locked_until')->nullable();
            $table->boolean($this->_pre_col . 'must_change')->default(false);
            $table->boolean($this->_pre_col . 'is_super_admin')->default(false)->index();
            $table->softDeletes($this->_pre_col . 'deleted_on');
            get_created_updated_by_db_column($table, $this->_pre_col);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('users');
    }
};
