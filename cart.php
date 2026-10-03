<?php
require_once 'config/auth.php';
require_payment();

$editingOrderId = (int)($_SESSION['edit_order_id'] ?? 0);
$editingOrder = null;
if ($editingOrderId > 0) {
    $q = $pdo->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
    $q->execute([$editingOrderId, $_SESSION['user_id']]);
    $editingOrder = $q->fetch();
    if (!$editingOrder || $editingOrder['status'] !== 'Pending') {
        if (isset($_SESSION['edit_cart_backup'])) {
            $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$_SESSION['user_id']]);
            $ins = $pdo->prepare("INSERT INTO cart_items(user_id,product_id,quantity) SELECT ?, id, ? FROM products WHERE id=?");
            foreach ($_SESSION['edit_cart_backup'] as $pid => $qty) {
                if ((int)$qty > 0) $ins->execute([$_SESSION['user_id'], (int)$qty, (int)$pid]);
            }
        }
        unset($_SESSION['edit_order_id'], $_SESSION['edit_cart_backup']);
        flash('error', $editingOrder ? 'Order #' . $editingOrder['order_number'] . ' is no longer editable because its status is ' . $editingOrder['status'] . '.' : 'The order being edited no longer exists.');
        redirect('orders.php');
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=$_POST['action']??'';
    if ($editingOrderId > 0 && in_array($action, ['add','update','remove'], true)) {
        $gate=$pdo->prepare("SELECT status FROM orders WHERE id=? AND user_id=? LIMIT 1");
        $gate->execute([$editingOrderId,$_SESSION['user_id']]);
        $gateStatus=$gate->fetchColumn();
        if ($gateStatus !== 'Pending') {
            unset($_SESSION['edit_order_id'], $_SESSION['edit_cart_backup']);
            flash('error', $gateStatus === false ? 'The order being edited no longer exists.' : 'Order editing stopped because the order status is ' . $gateStatus . '.');
            redirect('orders.php');
        }
    }
    if ($action==='add') {
        $pid=(int)$_POST['product_id']; $qty=max(1,(int)($_POST['quantity']??1)); $unit='piece';
        $stmt=$pdo->prepare("SELECT id,stock FROM products WHERE id=? AND is_active=1"); $stmt->execute([$pid]); $p=$stmt->fetch();
        if ($p) {
            $qty=min($qty,(int)$p['stock']);
            $stmt=$pdo->prepare("INSERT INTO cart_items(user_id,product_id,quantity,unit_type) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)");
            $stmt->execute([$_SESSION['user_id'],$pid,$qty,$unit]);
            flash('success','Product added to your cart.');
        }
        redirect(isset($_POST['buy_now'])?'checkout.php':'cart.php');
    }
    if ($action==='update') {
        foreach ($_POST['qty']??[] as $id=>$qty) {
            $qty=max(0,(int)$qty);
            if($qty===0) $pdo->prepare("DELETE FROM cart_items WHERE id=? AND user_id=?")->execute([(int)$id,$_SESSION['user_id']]);
            else {
                $check=$pdo->prepare("SELECT p.stock FROM cart_items c JOIN products p ON p.id=c.product_id WHERE c.id=? AND c.user_id=?");
                $check->execute([(int)$id,$_SESSION['user_id']]);
                $stock=$check->fetchColumn();
                if ($stock !== false) $qty=min($qty,(int)$stock);
                $pdo->prepare("UPDATE cart_items SET quantity=? WHERE id=? AND user_id=?")->execute([$qty,(int)$id,$_SESSION['user_id']]);
            }
        }
        flash('success',$editingOrderId ? 'Order items updated. Review the order and continue to Checkout to save it.' : 'Cart updated.');
        redirect('cart.php');
    }
    if ($action==='remove') {
        $pdo->prepare("DELETE FROM cart_items WHERE id=? AND user_id=?")->execute([(int)$_POST['id'],$_SESSION['user_id']]);
        redirect('cart.php');
    }
    if ($action==='cancel_edit') {
        if ($editingOrderId > 0 && isset($_SESSION['edit_cart_backup'])) {
            $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$_SESSION['user_id']]);
            $ins = $pdo->prepare("INSERT INTO cart_items(user_id,product_id,quantity) SELECT ?, id, ? FROM products WHERE id=?");
            foreach ($_SESSION['edit_cart_backup'] as $pid => $qty) {
                if ((int)$qty > 0) $ins->execute([$_SESSION['user_id'], (int)$qty, (int)$pid]);
            }
        }
        unset($_SESSION['edit_order_id'], $_SESSION['edit_cart_backup']);
        flash('success','Order editing cancelled. Your previous cart has been restored.');
        redirect('orders.php');
    }
}

$stmt=$pdo->prepare("SELECT c.id,c.quantity,c.unit_type,p.* FROM cart_items c JOIN products p ON p.id=c.product_id WHERE c.user_id=? ORDER BY c.id DESC");
$stmt->execute([$_SESSION['user_id']]); $items=$stmt->fetchAll();
$subtotal=0; foreach($items as $i) $subtotal += (float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price']))*$i['quantity'];
$page_title=$editingOrder ? 'Edit Order #' . $editingOrder['order_number'] : 'My Cart'; require 'config/header.php';
?>
<?php if ($editingOrder): ?>
<div class="alert success"><b>Editing Order #<?=e($editingOrder['order_number'])?></b><br>Change products or quantities below. Your existing order ID will be kept when you update the order.</div>
<div class="page-head"><div><h1>Edit Order</h1><p>Order #<?=e($editingOrder['order_number'])?> · Status: <span class="status">Pending</span></p></div><span class="badge">Existing Order</span></div>
<?php else: ?>
<h1>My Cart</h1>
<?php endif; ?>
<?php if(!$items): ?><div class="empty">Your cart is empty. <a href="products.php">Shop now →</a></div>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update">
<div class="cart-list">
<?php foreach($items as $i): ?>
<div class="cart-item"><div class="product-img small"><?=e($i['emoji'])?></div><div class="cart-info"><h3><?=e($i['product_code']??'')?> · <?=e($i['product_code']??'')?> · <?=e($i['name'])?></h3><small><?= $i['unit_type']==='set' ? ((int)$i['quantity']*10).' pieces equivalent' : 'Per piece' ?></small><small><?= $i['unit_type']==='set' ? ((int)$i['quantity']*10).' pieces equivalent' : 'Per piece' ?></small><strong><?=money((float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price'])))?> / <?=e($i['unit_type'])?></strong></div>
<input type="hidden" name="unit_type[<?= (int)$i['id'] ?>]" value="<?=e($i['unit_type'])?>"><input type="hidden" name="unit_type[<?= (int)$i['id'] ?>]" value="<?=e($i['unit_type'])?>"><input class="qty" type="number" name="qty[<?= (int)$i['id'] ?>]" min="0" max="<?= (int)$i['stock'] ?>" value="<?= (int)$i['quantity'] ?>">
<span><?=money((float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price']))*$i['quantity'])?></span>
</div>
<?php endforeach; ?></div>
<button class="btn btn-outline">Update Cart</button></form>
<div class="summary"><div>Subtotal <b><?=money($subtotal)?></b></div><div>Admin/Service Fee <b><?=money(150)?></b></div><hr><div class="total">Total <b><?=money($subtotal+150)?></b></div>
<a class="btn btn-block" href="checkout.php"><?= $editingOrder ? 'Update Order →' : 'Proceed to Checkout →' ?></a>
<?php if ($editingOrder): ?><form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel_edit"><button class="btn btn-outline btn-block">Cancel Editing</button></form><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($editingOrder): ?>
<script>
(function(){
  const orderId=<?=json_encode($editingOrderId)?>;
  let stopped=false;
  async function check(){
    if(stopped)return;
    try{
      const r=await fetch('order-status.php?id='+encodeURIComponent(orderId),{cache:'no-store'});
      if(!r.ok)return;
      const d=await r.json();
      if(d.ok && d.status!=='Pending'){
        stopped=true;
        document.querySelectorAll('input,button,a').forEach(el=>{ if(el.closest('form') || el.classList.contains('btn')) el.setAttribute('disabled','disabled'); });
        const box=document.createElement('div');
        box.className='alert error';
        box.innerHTML='<b>Editing stopped.</b> This order changed to <strong>'+String(d.status).replace(/[<>&"\']/g,'')+'</strong>. Returning to My Purchase...';
        const main=document.querySelector('main'); if(main) main.prepend(box);
        setTimeout(()=>location.href='orders.php',900);
      }
    }catch(e){}
  }
  check(); setInterval(check,1000);
})();
</script>
<?php endif; ?>
<?php require 'config/footer.php'; ?>
