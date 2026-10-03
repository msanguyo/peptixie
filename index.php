<?php
require_once 'config/auth.php';
if (!$isLogged = is_logged_in()) {
  $page_title='PEPTIXIE'; require 'config/header.php';
  ?>
  <section class="auth-home">
    <div class="auth-brand">PEPTIXIE <span>💉</span></div>
    <p class="muted">Food ordering made simple.</p>
    <div class="auth-home-actions"><a class="btn" href="login.php">Login</a><a class="btn btn-outline" href="register.php">Register</a></div>
  </section>
  <?php require 'config/footer.php'; exit;
}
require_payment();
$page_title='PEPTIXIE — Home';
$products=$pdo->query("SELECT * FROM products WHERE is_active=1 ORDER BY category,name")->fetchAll();
require 'config/header.php';
?>
<div class="page-head"><div><h1>Order List</h1><p>Choose from all available products.</p></div><a class="btn" href="cart.php">🛒 My Cart</a></div>
<div class="product-grid">
<?php foreach($products as $p): ?><article class="product-card">
  <div class="product-img"><?= e($p['emoji']) ?></div><h3><?= e($p['name']) ?></h3><p class="muted"><?= e($p['description']) ?></p>
  <strong class="price"><?= money((float)$p['price']) ?></strong>
  <form method="post" action="cart.php"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label>Quantity<input type="number" name="quantity" min="1" max="<?= (int)$p['stock'] ?>" value="1" required></label>
  <button class="btn btn-block">🛒 Add to Cart</button></form>
</article><?php endforeach; ?>
</div>
<?php require 'config/footer.php'; ?>