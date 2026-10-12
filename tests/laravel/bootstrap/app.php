<?php
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([GaussDb\Eloquent\GaussDBServiceProvider::class])
    ->withExceptions(function ($exceptions) {})
    ->create();
