<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private $_pre_col = 'ssp_';

    public function up(): void {
        Schema::create('sales_pipelines', function (Blueprint $table) {
            $table->unsignedBigInteger($this->_pre_col . 'id', true);
            $table->string($this->_pre_col . 'code', 12)->unique('uniq_deals_code');
            $table->string($this->_pre_col . 'name', 120);
            $table->string($this->_pre_col . 'account', 120)->nullable()->default(null);

            $table->unsignedBigInteger($this->_pre_col . 'owner_id')->nullable()->default(null)->index('idx_deals_owner');
            $table->foreign($this->_pre_col . 'owner_id')->references('usr_id')->on('users')->onDelete('set null');

            $table->enum($this->_pre_col . 'stage', array_keys(config('constants.DEAL_STAGES')))->default('discovery')->index('idx_deals_stage');
            $table->decimal($this->_pre_col . 'value', 14, 2)->default(0.00);
            $table->unsignedInteger($this->_pre_col . 'probability')->default(10);
            $table->date($this->_pre_col . 'close_date')->nullable()->index('idx_deals_close');
            $table->softDeletes($this->_pre_col . 'deleted_on')->nullable()->index('idx_deals_deleted');
            get_created_updated_by_db_column($table, $this->_pre_col);
        });
    }

    public function down(): void {
        Schema::dropIfExists('sales_pipelines');
    }
};
