<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('oauth_connect_clients', function (Blueprint $table) {
    $table->increments('id');
    $table->string('client_id', 100)->unique();
    $table->string('client_secret_hash', 255);
    $table->string('name', 120);
    $table->text('description')->nullable();
    $table->string('homepage_url', 255)->nullable();
    $table->string('icon_url', 255)->nullable();
    $table->text('redirect_uris');
    $table->text('scopes')->nullable();
    $table->string('grant_types', 120)->default('authorization_code refresh_token');
    $table->boolean('is_enabled')->default(true);
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
});
