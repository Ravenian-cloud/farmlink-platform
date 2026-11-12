<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => redirect('/marketplace'));
Route::get('/{page?}', static function (?string $page = 'marketplace') {
    $pages = ['marketplace', 'dashboard', 'community', 'impact', 'feedback', 'account', 'community-admin', 'ads'];
    abort_unless(in_array($page, $pages, true), 404);
    return view('app', ['page' => $page]);
})->where('page', 'marketplace|dashboard|community|impact|feedback|account|community-admin|ads');
