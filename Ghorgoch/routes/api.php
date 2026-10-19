<?php

use Illuminate\Support\Facades\Route;

require app_path('Auth/routes.php');
require app_path('Users/routes.php');
require app_path('Catalog/routes.php');
require app_path('Customers/routes.php');
require app_path('Orders/routes.php');
require app_path('Payments/routes.php');

Route::get('/', fn () => response()->json([
    'message' => 'Welcome to the EcommerceStore API.',
]));
