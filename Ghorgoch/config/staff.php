<?php

return [
    'protected_paths' => [
        'api/products', 'api/products/*', 'api/categories', 'api/categories/*',
        'api/inventory', 'api/inventory/*', 'api/customers', 'api/customers/*',
        'api/orders', 'api/orders/*', 'api/payments', 'api/payments/*',
    ],
    'public_routes' => [
        'POST api/customers/register',
        'GET api/products/health', 'HEAD api/products/health',
        'GET api/categories/health', 'HEAD api/categories/health',
        'GET api/inventory/health', 'HEAD api/inventory/health',
        'GET api/customers/health', 'HEAD api/customers/health',
        'GET api/orders/health', 'HEAD api/orders/health',
        'GET api/payments/health', 'HEAD api/payments/health',
    ],
    'permissions' => [
        'catalog.view', 'catalog.manage', 'inventory.adjust',
        'customers.view', 'customers.manage', 'orders.view', 'orders.process',
        'payments.record', 'payments.reconcile', 'staff.view', 'staff.manage',
        'roles.assign', 'permissions.assign', 'staff.assign_self',
    ],
];
