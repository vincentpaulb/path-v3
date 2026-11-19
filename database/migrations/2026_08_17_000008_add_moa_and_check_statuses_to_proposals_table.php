<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::table('proposals',function(Blueprint $t){$t->string('moa_status',100)->default('Pending')->after('funding_status');$t->timestamp('moa_status_updated_at')->nullable()->after('moa_status');$t->string('check_status',100)->default('Pending')->after('moa_status_updated_at');$t->timestamp('check_status_updated_at')->nullable()->after('check_status');});} public function down():void{Schema::table('proposals',function(Blueprint $t){$t->dropColumn(['moa_status','moa_status_updated_at','check_status','check_status_updated_at']);});} };
