<?php
require_once 'config/auth.php';
require_payment();

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
    flash('error', 'Invalid order.');
    redirect('orders.php');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
$stmt->execute([$orderId, $_SESSION['user_id']]);
$order = $stmt->fetch();
if (!$order) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

if ($order['status'] !== 'Pending') {
    flash('error', 'This order can no longer be edited because its status is ' . $order['status'] . '.');
    redirect('orders.php');
}

// Save the customer's existing cart so editing an order does not destroy it.
if (!isset($_SESSION['edit_order_id'])) {
    $backup = [];
    $q = $pdo->prepare("SELECT product_id, quantity FROM cart_items WHERE user_id=? ORDER BY id");
    $q->execute([$_SESSION['user_id']]);
    foreach ($q->fetchAll() as $row) {
        $backup[(int)$row['product_id']] = (int)$row['quantity'];
    }
    $_SESSION['edit_cart_backup'] = $backup;
}

$_SESSION['edit_order_id'] = $orderId;

$itemsStmt = $pdo->prepare("SELECT oi.product_id, oi.quantity, p.is_active, p.stock FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id");
$itemsStmt->execute([$orderId]);
$orderItems = $itemsStmt->fetchAll();
if (!$orderItems) {
    flash('error', 'This order has no editable products.');
    redirect('orders.php');
}
foreach ($orderItems as $item) {
    if (!$item['product_id'] || (int)$item['is_active'] !== 1) {
        flash('error', 'This order contains a product that is no longer available, so it cannot be edited.');
        redirect('orders.php');
    }
}
foreach ($orderItems as $item) {
    if ((int)$item['quantity'] > (int)$item['stock']) {
        flash('error', 'Product stock changed for one of the items in this order. Please contact the admin before editing it.');
        redirect('orders.php');
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$_SESSION['user_id']]);
    $ins = $pdo->prepare("INSERT INTO cart_items(user_id,product_id,quantity) SELECT ?, id, ? FROM products WHERE id=? AND is_active=1");
    foreach ($orderItems as $item) {
        $ins->execute([$_SESSION['user_id'], (int)$item['quantity'], (int)$item['product_id']]);
    }
    $pdo->commit();
    flash('success', 'Order #' . $order['order_number'] . ' is ready to edit. Update the products in My Cart, then continue to Checkout.');
    redirect('cart.php?editing=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    unset($_SESSION['edit_order_id'], $_SESSION['edit_cart_backup']);
    flash('error', 'Unable to prepare this order for editing.');
    redirect('orders.php');
}
