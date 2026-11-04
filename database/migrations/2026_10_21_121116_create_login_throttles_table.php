<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'lgt_';

    public function up(): void
    {
        Schema::create('login_throttles', function (Blueprint $table) {
            $table->char($this->_pre_col . 'key_hash', 64)->primary();
            $table->string($this->_pre_col . 'scope', 10);
            $table->unsignedInteger($this->_pre_col . 'fails')->default(0);
            $table->dateTime($this->_pre_col . 'first_fail_at');
            $table->dateTime($this->_pre_col . 'last_fail_at')->index('idx_lt_last');
            $table->dateTime($this->_pre_col . 'blocked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_throttles');
    }
};
