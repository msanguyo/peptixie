<?php
require_once 'config/auth.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id=? AND is_active=1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) redirect('products.php');
$page_title = $product['name'];
require 'config/header.php';
?>
<div class="detail">
  <div class="detail-img"><?= e($product['emoji']) ?></div>
  <div>
    <p class="muted"><?= e($product['category']) ?></p>
    <h1><?= e($product['name']) ?></h1>
    <div class="stars">★★★★★ <small>(24 reviews)</small></div>
    <h2 class="price big">Per piece: <?=money((float)($product['piece_price']?:$product['price']))?> · Per set (10 pcs): <?=money((float)($product['set_price']?:$product['price']*10))?></h2>
    <p><?= e($product['description']) ?></p>
    <p class="stock">● In Stock</p>
    <form method="post" action="cart.php" class="buy-form">
      <input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $id ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Unit <select name="unit_type"><option value="piece">Per Piece</option><option value="set">Per Set (10 pieces)</option></select></label><label>Unit <select name="unit_type"><option value="piece">Per Piece</option><option value="set">Per Set (10 pieces)</option></select></label><label>Quantity <input class="qty" type="number" name="quantity" value="1" min="1" max="<?= (int)$product['stock'] ?>"></label>
      <button class="btn">🛒 Add to Cart</button>
      <button class="btn btn-outline" name="buy_now" value="1">Buy Now</button>
    </form>
  </div>
</div>
<?php require 'config/footer.php'; ?>