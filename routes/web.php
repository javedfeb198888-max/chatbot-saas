<?php

use Illuminate\Support\Facades\Route;

// Clients sign in at /app, the platform owner at /admin.
Route::redirect('/', '/app');
