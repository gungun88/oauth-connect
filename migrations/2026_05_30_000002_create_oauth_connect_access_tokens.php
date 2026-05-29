<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('oauth_connect_access_tokens', function (Blueprint $table) {
    $table->increments('id');
    $table->string('token', 128)->unique();
    $table->string('client_id', 100)->index();
    $table->integer('user_id')->unsigned()->index();
    $table->text('scope')->nullable();
    $table->dateTime('expires_at')->index();
    $table->dateTime('revoked_at')->nullable()->index();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
});
