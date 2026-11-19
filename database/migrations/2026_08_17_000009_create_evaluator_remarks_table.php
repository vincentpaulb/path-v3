<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::create('evaluator_remarks',function(Blueprint $t){$t->id();$t->foreignId('proposal_id')->constrained()->cascadeOnDelete();$t->foreignId('evaluator_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->restrictOnDelete();$t->string('action',20);$t->text('remark');$t->timestamps();});} public function down():void{Schema::dropIfExists('evaluator_remarks');} };
