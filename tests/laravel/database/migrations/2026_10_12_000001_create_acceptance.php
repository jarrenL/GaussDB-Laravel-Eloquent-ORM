<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(getenv('GAUSS_SCHEMA').'.'.getenv('GAUSS_ARTISAN_TABLE'), function(Blueprint $t) {
            $t->id(); $t->string('label',40);
        });
    }
    public function down(): void { Schema::dropIfExists(getenv('GAUSS_SCHEMA').'.'.getenv('GAUSS_ARTISAN_TABLE')); }
};
