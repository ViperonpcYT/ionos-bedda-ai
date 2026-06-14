<?php
require __DIR__ . '/../api/lib/product-shipping-specs.php';

$names = [
    'Ultra Bee Brake Kit',
    'Titanium Bolt Kit',
    '17x1.6 Supermoto Wheel Set for Talaria & Sur-Ron',
    '3-Inch Baja Style LED Headlight',
    'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt',
    'Rear Brake Pads for Sur-Ron',
    'Sur-Ron Ultra Bee Front & Rear Fender Set',
];

foreach ($names as $n) {
    $s = onlybikes_lookup_product_shipping($n);
    echo $n . ' => ' . $s['weight_g'] . 'g (' . $s['label'] . ")\n";
}

$carts = [
    [['product' => '3-Inch Baja Style LED Headlight', 'price' => 49.99, 'quantity' => 1]],
    [['product' => 'Ultra Bee Brake Kit', 'price' => 349.99, 'quantity' => 1]],
    [['product' => 'Ultra Bee Brake Kit', 'price' => 349.99, 'quantity' => 6], ['product' => 'Titanium Bolt Kit', 'price' => 89.99, 'quantity' => 2]],
];

foreach ($carts as $i => $cart) {
    $p = onlybikes_calculate_cart_package($cart);
    echo "Cart $i: {$p['weight']}g {$p['package_type']} {$p['size_x']}x{$p['size_y']}x{$p['size_z']}in\n";
}
