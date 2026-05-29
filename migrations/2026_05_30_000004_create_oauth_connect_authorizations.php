<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('oauth_connect_authorizations', function (Blueprint $table) {
    $table->increments('id');
    $table->string('client_id', 100)->index();
    $table->integer('user_id')->unsigned()->index();
    $table->text('scope')->nullable();
    $table->dateTime('authorized_at')->nullable();
    $table->dateTime('revoked_at')->nullable()->index();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->unique(['client_id', 'user_id'], 'oauth_connect_authorizations_client_user_unique');
});
