<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a connected local demo store.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $customers = $this->seedUsersAndCustomers();
            $categories = $this->seedCategories();
            $products = $this->seedProducts($categories);
            $this->seedTokens($customers);
            $this->seedAddresses($customers);
            $this->seedTags($products);
            $this->seedCarts($customers, $products);
            $coupons = $this->seedCoupons();
            $orders = $this->seedOrders($customers, $products, $coupons);
            $this->seedPayments($orders);
            $this->seedInventory($products, $orders);
        });
    }

    /**
     * @return array<string, array{id: int, email: string}>
     */
    private function seedUsersAndCustomers(): array
    {
        $accounts = [
            ['name' => 'Store Administrator', 'email' => 'admin@example.test', 'role' => 'admin', 'customer' => false, 'verified' => true],
            ['name' => 'Store Staff', 'email' => 'staff@example.test', 'role' => 'staff', 'customer' => false, 'verified' => true],
            ['name' => 'Test User', 'email' => 'test@example.com', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Ayesha Rahman', 'email' => 'customer1@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Imran Hossain', 'email' => 'customer2@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Nusrat Jahan', 'email' => 'customer3@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Tanvir Ahmed', 'email' => 'customer4@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Farzana Akter', 'email' => 'customer5@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Sabbir Khan', 'email' => 'customer6@example.test', 'role' => 'customer', 'customer' => true, 'verified' => true],
            ['name' => 'Maliha Chowdhury', 'email' => 'customer7@example.test', 'role' => 'customer', 'customer' => true, 'verified' => false],
        ];
        $customers = [];
        $password = Hash::make('password');

        foreach ($accounts as $index => $account) {
            $now = now();
            $userId = $this->upsertId('users', ['email' => $account['email']], [
                'name' => $account['name'],
                'phone' => sprintf('+880170000%04d', $index + 1),
                'password' => $password,
                'role' => $account['role'],
                'email_verified_at' => $account['verified'] ? $now : null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! $account['customer']) {
                continue;
            }

            $customerId = $this->upsertId('customers', ['user_id' => $userId], [
                'date_of_birth' => now()->subYears(25 + ($index % 15))->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $customers[$account['email']] = ['id' => $customerId, 'email' => $account['email']];
        }

        return $customers;
    }

    /**
     * @return array<string, int>
     */
    private function seedCategories(): array
    {
        $now = now();
        $rootId = $this->upsertId('categories', ['slug' => 'demo-store'], [
            'parent_id' => null,
            'name' => 'Demo Store',
            'description' => 'Top-level category for the sample catalog.',
            'image_path' => null,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categories = [];
        foreach (['Everyday Wear', 'Accessories', 'Home and Living', 'Featured Finds'] as $index => $name) {
            $slug = Str::slug($name);
            $categories[$slug] = $this->upsertId('categories', ['slug' => $slug], [
                'parent_id' => $rootId,
                'name' => $name,
                'description' => "Sample products in the {$name} category.",
                'image_path' => null,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $categories;
    }

    /**
     * @param  array<string, int>  $categories
     * @return list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>
     */
    private function seedProducts(array $categories): array
    {
        $imageRoot = public_path('productimage');
        if (! File::isDirectory($imageRoot)) {
            throw new RuntimeException('The public/productimage directory is required to seed the demo catalog.');
        }

        $groups = [];
        $directories = File::directories($imageRoot);
        usort($directories, fn (string $left, string $right): int => strnatcasecmp(basename($left), basename($right)));

        foreach ($directories as $directory) {
            if (! preg_match('/^product\d+$/i', basename($directory))) {
                continue;
            }

            $images = $this->supportedImages(File::files($directory));
            if ($images !== []) {
                $groups[] = ['name' => 'Demo '.Str::headline(basename($directory)), 'images' => $images];
            }
        }

        foreach ($this->supportedImages(File::files($imageRoot)) as $image) {
            $groups[] = ['name' => 'Featured demo item '.(count($groups) + 1), 'images' => [$image]];
        }

        if ($groups === []) {
            throw new RuntimeException('No supported product images were found in public/productimage.');
        }

        $products = [];
        $categoryIds = array_values($categories);

        foreach ($groups as $productIndex => $group) {
            $slug = 'demo-product-'.($productIndex + 1);
            $now = now();
            $productId = $this->upsertId('products', ['slug' => $slug], [
                'category_id' => $categoryIds[$productIndex % count($categoryIds)],
                'name' => $group['name'],
                'description' => 'Sample catalog item using the supplied '.count($group['images']).' local product image(s).',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $variants = [];

            foreach ($group['images'] as $imageIndex => $image) {
                $sku = sprintf('DEMO-P%02d-V%02d', $productIndex + 1, $imageIndex + 1);
                $price = (float) (500 + ($productIndex * 125) + ($imageIndex * 50));
                $attributes = ['option' => 'Option '.($imageIndex + 1)];
                $variantId = $this->upsertId('product_variants', ['sku' => $sku], [
                    'product_id' => $productId,
                    'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR),
                    'price' => $price,
                    'compare_at_price' => $price * 1.15,
                    'stock_quantity' => 30,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $imagePath = str_replace('\\', '/', Str::after($image->getPathname(), public_path().DIRECTORY_SEPARATOR));

                DB::table('product_images')->updateOrInsert(
                    ['product_id' => $productId, 'image_path' => $imagePath],
                    [
                        'variant_id' => $variantId,
                        'alt_text' => $group['name'].' photo '.($imageIndex + 1),
                        'is_primary' => $imageIndex === 0,
                        'sort_order' => $imageIndex,
                        'created_at' => $now,
                    ],
                );

                $variants[] = [
                    'id' => $variantId,
                    'sku' => $sku,
                    'price' => $price,
                    'attributes' => $attributes,
                    'product_name' => $group['name'],
                ];
            }

            $products[] = ['id' => $productId, 'slug' => $slug, 'variants' => $variants];
        }

        return $products;
    }

    /**
     * @param  list<SplFileInfo>  $files
     * @return list<SplFileInfo>
     */
    private function supportedImages(array $files): array
    {
        $images = array_values(array_filter(
            $files,
            fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'], true),
        ));
        usort($images, fn (SplFileInfo $left, SplFileInfo $right): int => strnatcasecmp($left->getFilename(), $right->getFilename()));

        return $images;
    }

    /**
     * @param  array<string, array{id: int, email: string}>  $customers
     */
    private function seedTokens(array $customers): void
    {
        $unverified = $customers['customer7@example.test'];
        DB::table('email_verification_tokens')->updateOrInsert(
            ['user_id' => DB::table('users')->where('email', $unverified['email'])->value('id')],
            [
                'token_hash' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addDay(),
                'created_at' => now(),
            ],
        );

        $resetEmail = 'test@example.com';
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $resetEmail],
            ['token' => Hash::make(Str::random(64)), 'created_at' => now()],
        );
    }

    /**
     * @param  array<string, array{id: int, email: string}>  $customers
     */
    private function seedAddresses(array $customers): void
    {
        $locations = [
            ['area' => 'Dhanmondi', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postal_code' => '1209'],
            ['area' => 'Agrabad', 'city' => 'Chattogram', 'district' => 'Chattogram', 'postal_code' => '4100'],
            ['area' => 'Zindabazar', 'city' => 'Sylhet', 'district' => 'Sylhet', 'postal_code' => '3100'],
            ['area' => 'Khalishpur', 'city' => 'Khulna', 'district' => 'Khulna', 'postal_code' => '9000'],
        ];

        foreach (array_values($customers) as $index => $customer) {
            $location = $locations[$index % count($locations)];
            foreach (['Home', 'Office'] as $addressIndex => $label) {
                $now = now();
                DB::table('addresses')->updateOrInsert(
                    ['customer_id' => $customer['id'], 'label' => $label],
                    [
                        'recipient_name' => $customer['email'] === 'test@example.com' ? 'Test User' : Str::before($customer['email'], '@'),
                        'phone' => sprintf('+880180000%04d', ($index * 2) + $addressIndex + 1),
                        'address_line1' => $addressIndex === 0 ? 'House 12, Road 4' : 'Building 8, Level 3',
                        'address_line2' => null,
                        'area' => $location['area'],
                        'city' => $location['city'],
                        'district' => $location['district'],
                        'postal_code' => $location['postal_code'],
                        'country_code' => 'BD',
                        'is_default' => $addressIndex === 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    /**
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     */
    private function seedTags(array $products): void
    {
        $tags = [];
        foreach (['New Arrival', 'Popular', 'Demo Item', 'In Stock'] as $name) {
            $slug = Str::slug($name);
            $tags[] = $this->upsertId('tags', ['slug' => $slug], [
                'name' => $name,
                'created_at' => now(),
            ]);
        }

        foreach ($products as $index => $product) {
            foreach ([$tags[$index % count($tags)], $tags[2]] as $tagId) {
                DB::table('product_tags')->insertOrIgnore([
                    'product_id' => $product['id'],
                    'tag_id' => $tagId,
                ]);
            }
        }
    }

    /**
     * @param  array<string, array{id: int, email: string}>  $customers
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     */
    private function seedCarts(array $customers, array $products): void
    {
        $variants = $this->flattenVariants($products);

        foreach (array_values($customers) as $customerIndex => $customer) {
            $now = now();
            $cartId = $this->upsertId('carts', ['customer_id' => $customer['id']], [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $firstVariantIndex = ($customerIndex * 2) % count($variants);

            foreach ([0, 1] as $itemIndex) {
                $variant = $variants[($firstVariantIndex + $itemIndex) % count($variants)];
                DB::table('cart_items')->updateOrInsert(
                    ['cart_id' => $cartId, 'variant_id' => $variant['id']],
                    [
                        'quantity' => $itemIndex + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedCoupons(): array
    {
        $now = now();
        $coupons = [
            ['code' => 'DEMO10', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 500, 'min_order_amount' => 1000],
            ['code' => 'DEMO250', 'discount_type' => 'fixed', 'discount_value' => 250, 'max_discount' => null, 'min_order_amount' => 1500],
            ['code' => 'DEMO5', 'discount_type' => 'percentage', 'discount_value' => 5, 'max_discount' => 300, 'min_order_amount' => 500],
        ];
        $ids = [];

        foreach ($coupons as $coupon) {
            $ids[$coupon['code']] = $this->upsertId('coupons', ['code' => $coupon['code']], $coupon + [
                'usage_limit' => 100,
                'usage_count' => $coupon['code'] === 'DEMO10' ? 1 : 0,
                'starts_at' => now()->subMonth(),
                'expires_at' => now()->addYear(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * @param  array<string, array{id: int, email: string}>  $customers
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     * @param  array<string, int>  $coupons
     * @return list<array{id: int, number: string, status: string, payment_status: string, variant_id: int, quantity: int, total: float}>
     */
    private function seedOrders(array $customers, array $products, array $coupons): array
    {
        $variants = $this->flattenVariants($products);
        $statuses = [
            ['delivered', 'paid'],
            ['shipped', 'paid'],
            ['processing', 'paid'],
            ['pending', 'pending'],
            ['delivered', 'paid'],
            ['cancelled', 'failed'],
            ['delivered', 'paid'],
            ['pending', 'pending'],
        ];
        $orders = [];
        $addresses = array_values($customers);

        foreach ($addresses as $index => $customer) {
            [$status, $paymentStatus] = $statuses[$index % count($statuses)];
            $variant = $variants[($index * 3) % count($variants)];
            $quantity = ($index % 3) + 1;
            $subtotal = $variant['price'] * $quantity;
            $couponId = $index === 1 && $subtotal >= 1000 ? $coupons['DEMO10'] : null;
            $discount = $couponId ? min($subtotal * 0.10, 500) : 0;
            $shipping = $subtotal >= 2000 ? 0 : 120;
            $total = $subtotal - $discount + $shipping;
            $orderNumber = sprintf('DEMO-2026-%04d', $index + 1);
            $address = DB::table('addresses')
                ->where('customer_id', $customer['id'])
                ->where('label', 'Home')
                ->first();
            $addressSnapshot = [
                'recipient_name' => $address->recipient_name,
                'phone' => $address->phone,
                'address_line1' => $address->address_line1,
                'area' => $address->area,
                'city' => $address->city,
                'district' => $address->district,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
            ];
            $createdAt = now()->subDays(30 - ($index * 3));
            $orderId = $this->upsertId('orders', ['order_number' => $orderNumber], [
                'customer_id' => $customer['id'],
                'coupon_id' => $couponId,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'shipping_address' => json_encode($addressSnapshot, JSON_THROW_ON_ERROR),
                'billing_address' => json_encode($addressSnapshot, JSON_THROW_ON_ERROR),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'shipping_fee' => $shipping,
                'tax_amount' => 0,
                'total_amount' => $total,
                'customer_note' => 'Sample order for development and testing.',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $now = now();
            DB::table('order_items')->updateOrInsert(
                ['order_id' => $orderId, 'sku' => $variant['sku']],
                [
                    'product_id' => $this->productIdForVariant($products, $variant['id']),
                    'variant_id' => $variant['id'],
                    'product_name' => $variant['product_name'],
                    'variant_attributes' => json_encode($variant['attributes'], JSON_THROW_ON_ERROR),
                    'unit_price' => $variant['price'],
                    'quantity' => $quantity,
                    'line_total' => $variant['price'] * $quantity,
                    'created_at' => $createdAt,
                ],
            );

            $orders[] = [
                'id' => $orderId,
                'number' => $orderNumber,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'variant_id' => $variant['id'],
                'quantity' => $quantity,
                'total' => $total,
            ];
        }

        return $orders;
    }

    /**
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     */
    private function productIdForVariant(array $products, int $variantId): int
    {
        foreach ($products as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['id'] === $variantId) {
                    return $product['id'];
                }
            }
        }

        throw new RuntimeException("Could not find product for demo variant {$variantId}.");
    }

    /**
     * @param  list<array{id: int, number: string, status: string, payment_status: string, variant_id: int, quantity: int, total: float}>  $orders
     */
    private function seedPayments(array $orders): void
    {
        foreach ($orders as $order) {
            $successful = $order['payment_status'] === 'paid';
            $pending = $order['payment_status'] === 'pending';
            $now = now();
            DB::table('payments')->updateOrInsert(
                ['provider' => 'DemoPay', 'transaction_id' => 'DEMO-TXN-'.$order['number']],
                [
                    'order_id' => $order['id'],
                    'amount' => $order['total'],
                    'currency' => 'BDT',
                    'status' => $successful ? 'successful' : ($pending ? 'pending' : 'failed'),
                    'provider_reference' => 'DEMO-REF-'.$order['number'],
                    'provider_response' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                    'paid_at' => $successful ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /**
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     * @param  list<array{id: int, number: string, status: string, payment_status: string, variant_id: int, quantity: int, total: float}>  $orders
     */
    private function seedInventory(array $products, array $orders): void
    {
        $sold = [];
        foreach ($orders as $order) {
            if ($order['status'] === 'cancelled') {
                continue;
            }
            $sold[$order['variant_id']] = ($sold[$order['variant_id']] ?? 0) + $order['quantity'];
        }

        foreach ($this->flattenVariants($products) as $variant) {
            $openingStock = 30;
            DB::table('product_variants')->where('id', $variant['id'])->update([
                'stock_quantity' => $openingStock - ($sold[$variant['id']] ?? 0),
                'updated_at' => now(),
            ]);

            $openingExists = DB::table('inventory_transactions')
                ->where('variant_id', $variant['id'])
                ->where('transaction_type', 'stock_in')
                ->where('note', 'Demo seed opening stock')
                ->exists();
            if (! $openingExists) {
                DB::table('inventory_transactions')->insert([
                    'variant_id' => $variant['id'],
                    'order_id' => null,
                    'transaction_type' => 'stock_in',
                    'quantity_change' => $openingStock,
                    'note' => 'Demo seed opening stock',
                    'created_at' => now(),
                ]);
            }
        }

        foreach ($orders as $order) {
            if ($order['status'] === 'cancelled') {
                continue;
            }
            $exists = DB::table('inventory_transactions')
                ->where('variant_id', $order['variant_id'])
                ->where('order_id', $order['id'])
                ->where('transaction_type', 'stock_out')
                ->exists();
            if (! $exists) {
                DB::table('inventory_transactions')->insert([
                    'variant_id' => $order['variant_id'],
                    'order_id' => $order['id'],
                    'transaction_type' => 'stock_out',
                    'quantity_change' => -$order['quantity'],
                    'note' => 'Demo order fulfillment',
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  list<array{id: int, slug: string, variants: list<array{id: int, sku: string, price: float, attributes: array<string, string>}>}>  $products
     * @return list<array{id: int, sku: string, price: float, attributes: array<string, string>}>
     */
    private function flattenVariants(array $products): array
    {
        $variants = [];
        foreach ($products as $product) {
            array_push($variants, ...$product['variants']);
        }

        return $variants;
    }

    /**
     * Insert or update a row identified by its stable demo key.
     */
    private function upsertId(string $table, array $identity, array $values): int
    {
        DB::table($table)->updateOrInsert($identity, $values);
        $id = DB::table($table)->where($identity)->value('id');

        if ($id === null) {
            throw new RuntimeException("Could not retrieve the seeded row from {$table}.");
        }

        return (int) $id;
    }
}
