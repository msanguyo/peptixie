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

$stmt=$pdo->prepare("SELECT c.id,c.product_id,c.quantity,c.unit_type,p.* FROM cart_items c JOIN products p ON p.id=c.product_id WHERE c.user_id=? AND p.is_active=1 ORDER BY c.id");
$stmt->execute([$_SESSION['user_id']]); $items=$stmt->fetchAll();
if(!$items) redirect('cart.php');
foreach ($items as $i) {
    if ((int)$i['quantity'] > (int)$i['stock']) {
        flash('error', 'The quantity for ' . $i['name'] . ' exceeds the current stock. Please update My Cart.');
        redirect('cart.php');
    }
}
$u=current_user(); $subtotal=0; foreach($items as $i) {$unitPrice=(float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price'])); $subtotal += $unitPrice*(int)$i['quantity'];}
$fee=150; $total=$subtotal+$fee; $error='';
$qrFile=__DIR__.'/assets/images/gcash_qr.png';
$qrExists=is_file($qrFile);
$defaultMethod=$editingOrder['delivery_method'] ?? 'Lalamove';
$defaultPayment=$editingOrder['payment_method'] ?? 'GCash';
$defaultName=$editingOrder['delivery_name'] ?? $u['full_name'];
$defaultAddress=$editingOrder['delivery_address'] ?? ''; 
$defaultPhone=$editingOrder['delivery_phone'] ?? $u['phone'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $method=$_POST['delivery_method']??$defaultMethod;
  $name=trim($_POST['delivery_name']??$defaultName); $address=trim($_POST['delivery_address']??$defaultAddress); $phone=trim($_POST['delivery_phone']??$defaultPhone);
  $payment=$_POST['payment_method']??$defaultPayment;
  if(!in_array($method,['Lalamove','J&T'],true)) $error='Invalid delivery method.';
  elseif(!in_array($payment,['GCash','Cash on Delivery'],true)) $error='Invalid payment method.';
  elseif($method==='J&T' && (!$name||!$address||!$phone)) $error='Please complete J&T delivery details.';
  else {
    try{
      $pdo->beginTransaction();
      // Lock the products being ordered so stock cannot change underneath this checkout/update.
      $lockProducts=$pdo->prepare("SELECT p.id,p.name,p.price,p.stock,p.is_active FROM products p JOIN cart_items c ON c.product_id=p.id WHERE c.user_id=? FOR UPDATE");
      $lockProducts->execute([$_SESSION['user_id']]);
      $lockedProducts=[];
      foreach($lockProducts->fetchAll() as $lp){ $lockedProducts[(int)$lp['id']]=$lp; }
      foreach($items as $i){
        $lp=$lockedProducts[(int)$i['product_id']] ?? null;
        if(!$lp || (int)$lp['is_active']!==1 || (int)$i['quantity']>(int)$lp['stock']) throw new RuntimeException('Stock changed. Please review your cart.');
      }
      if ($editingOrderId > 0) {
        // Lock and re-check the order so an admin status change wins over an edit race.
        $lock=$pdo->prepare("SELECT * FROM orders WHERE id=? AND user_id=? FOR UPDATE");
        $lock->execute([$editingOrderId,$_SESSION['user_id']]);
        $lockedOrder=$lock->fetch();
        if (!$lockedOrder) throw new RuntimeException('Order not found.');
        if ($lockedOrder['status'] !== 'Pending') throw new RuntimeException('Order is no longer editable.');

        $update=$pdo->prepare("UPDATE orders SET subtotal=?, service_fee=?, total=?, delivery_method=?, payment_method=?, delivery_name=?, delivery_address=?, delivery_phone=?, updated_at=NOW() WHERE id=? AND user_id=? AND status='Pending'");
        $update->execute([$subtotal,$fee,$total,$method,$payment,$method==='Lalamove' ? '' : $name,$method==='Lalamove' ? '' : $address,$method==='Lalamove' ? '' : $phone,$editingOrderId,$_SESSION['user_id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Order is no longer editable.');

        $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$editingOrderId]);
        $ins=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,price,quantity,unit_type,product_code) VALUES(?,?,?,?,?,?,?)");
        foreach($items as $i){$up=(float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price']));$ins->execute([$editingOrderId,$i['id'],$i['name'],$up,$i['quantity'],$i['unit_type'],$i['product_code']??'']);}

        $pdo->prepare("INSERT INTO delivery_history(order_id,user_id,order_number,previous_status,new_status,courier,delivery_name,delivery_address,delivery_phone,changed_by_user_id,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$editingOrderId,$_SESSION['user_id'],$lockedOrder['order_number'],'Pending','Pending',$method,$method==='Lalamove' ? '' : $name,$method==='Lalamove' ? '' : $address,$method==='Lalamove' ? '' : $phone,$_SESSION['user_id'],'Order edited by customer']);

        $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$_SESSION['user_id']]);
        // Restore the customer's pre-edit cart, if one existed.
        if (isset($_SESSION['edit_cart_backup'])) {
          $insCart=$pdo->prepare("INSERT INTO cart_items(user_id,product_id,quantity) SELECT ?, id, ? FROM products WHERE id=?");
          foreach ($_SESSION['edit_cart_backup'] as $pid => $qty) {
            if ((int)$qty > 0) $insCart->execute([$_SESSION['user_id'], (int)$qty, (int)$pid]);
          }
        }
        $orderNo=$lockedOrder['order_number'];
        $pdo->commit();
        unset($_SESSION['edit_order_id'], $_SESSION['edit_cart_backup']);
        flash('success','Order #' . $orderNo . ' was updated successfully. The same Order ID was kept.');
        redirect("orders.php?updated=" . $editingOrderId);
      }

      $orderNo='SS'.date('YmdHis').random_int(100,999);
      $stmt=$pdo->prepare("INSERT INTO orders(user_id,order_number,subtotal,service_fee,total,delivery_method,payment_method,status,delivery_name,delivery_address,delivery_phone) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$_SESSION['user_id'],$orderNo,$subtotal,$fee,$total,$method,$payment,'Pending',$method==='Lalamove' ? '' : $name,$method==='Lalamove' ? '' : $address,$method==='Lalamove' ? '' : $phone]);
      $orderId=(int)$pdo->lastInsertId();
      $pdo->prepare("INSERT INTO delivery_history(order_id,user_id,order_number,previous_status,new_status,courier,delivery_name,delivery_address,delivery_phone,changed_by_user_id,remarks) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$orderId,$_SESSION['user_id'],$orderNo,NULL,'Pending',$method,$method==='Lalamove' ? '' : $name,$method==='Lalamove' ? '' : $address,$method==='Lalamove' ? '' : $phone,$_SESSION['user_id'],'Order placed']);
      $ins=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,price,quantity,unit_type,product_code) VALUES(?,?,?,?,?,?,?)");
      foreach($items as $i){$up=(float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price']));$ins->execute([$orderId,$i['id'],$i['name'],$up,$i['quantity'],$i['unit_type'],$i['product_code']??'']);}
      $pdo->prepare("DELETE FROM cart_items WHERE user_id=?")->execute([$_SESSION['user_id']]);
      $pdo->commit();
      redirect("orders.php?placed=$orderId");
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$msg=$e->getMessage(); $error=$msg==='Order is no longer editable.'?'This order is no longer editable. The admin has already changed its status.':(($msg==='Stock changed. Please review your cart.') ? $msg : ($editingOrderId?'Unable to update the order. Please try again.':'Unable to place order. Please try again.'));}
  }
}
$page_title=$editingOrder ? 'Update Order #' . $editingOrder['order_number'] : 'Checkout'; require 'config/header.php';
?>
<?php if($editingOrder): ?><div class="alert success"><b>Updating Order #<?=e($editingOrder['order_number'])?></b><br>Your changes will update this existing order. A new order number will not be created.</div><?php endif; ?>
<h1><?= $editingOrder ? 'Update Order' : 'Checkout' ?></h1>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<form method="post" class="checkout-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<section class="panel"><h2>Choose Courier</h2>
<label class="radio"><input type="radio" name="delivery_method" value="Lalamove" <?= $defaultMethod==='Lalamove'?'checked':'' ?>> Lalamove <small>No user delivery details required</small></label>
<label class="radio"><input type="radio" name="delivery_method" value="J&T" <?= $defaultMethod==='J&T'?'checked':'' ?>> J&T Express <small>Delivery details required</small></label>
<div class="delivery-fields">
<label>Full Name<input name="delivery_name" value="<?=e($defaultName)?>"></label>
<label>Address<textarea name="delivery_address"><?=e($defaultAddress)?></textarea></label>
<label>Contact Number<input name="delivery_phone" value="<?=e($defaultPhone)?>"></label>
</div>
<h2>Payment Method</h2>
<label class="radio"><input type="radio" name="payment_method" value="GCash" <?= $defaultPayment==='GCash'?'checked':'' ?>> GCash</label>
<div id="gcash-payment-box" class="checkout-payment-box" <?= $defaultPayment==='GCash'?'':'hidden' ?>>
  <div class="checkout-payment-head"><div><strong>Pay with GCash</strong><span>Scan the QR code using your GCash app.</span></div><b><?=money($total)?></b></div>
  <div class="checkout-qr-wrap">
    <?php if($qrExists): ?>
      <img src="assets/images/gcash_qr.png" alt="GCash payment QR code" class="checkout-qr">
    <?php else: ?>
      <div class="checkout-qr-missing">GCash QR code is not configured yet.<br><small>Admin can upload it from Admin → Payments.</small></div>
    <?php endif; ?>
  </div>
  <p class="small-text">Please make sure the amount you send matches the checkout total above.</p>
</div>
<label class="radio"><input type="radio" name="payment_method" value="Cash on Delivery" <?= $defaultPayment==='Cash on Delivery'?'checked':'' ?>> Cash on Delivery</label>
</section>
<aside class="panel"><h2>Order Summary</h2>
<?php foreach($items as $i): ?><div class="summary-row"><span><?=e($i['product_code']??'')?> <?=e($i['name'])?> · <?=e($i['unit_type']??'piece')?> × <?= (int)$i['quantity']?></span><b><?=money((float)(($i['unit_type']==='set' && !empty($i['set_price']))?$i['set_price']:(($i['unit_type']==='piece' && !empty($i['piece_price']))?$i['piece_price']:$i['price']))*$i['quantity'])?></b></div><?php endforeach; ?>
<hr><div class="summary-row"><span>Subtotal</span><b><?=money($subtotal)?></b></div><div class="summary-row"><span>Admin Fee</span><b><?=money($fee)?></b></div><div class="summary-row total"><span>Total</span><b><?=money($total)?></b></div>
<button class="btn btn-block"><?= $editingOrder ? '✏️ Update Existing Order' : '🔒 Place Order' ?></button>
<a class="btn btn-outline btn-block" href="cart.php">← Back to My Cart</a></aside></form>
<script>
(function(){
  const radios=[...document.querySelectorAll('input[name="delivery_method"]')];
  const fields=[...document.querySelectorAll('.delivery-fields input, .delivery-fields textarea')];
  const paymentRadios=[...document.querySelectorAll('input[name="payment_method"]')];
  const gcashBox=document.getElementById('gcash-payment-box');
  function sync(){
    const selected=document.querySelector('input[name="delivery_method"]:checked')?.value;
    const disabled=selected!=='J&T';
    fields.forEach(el=>{el.disabled=disabled; el.setAttribute('aria-disabled', disabled ? 'true' : 'false');});
  }
  function syncPayment(){
    if(!gcashBox)return;
    const selected=document.querySelector('input[name="payment_method"]:checked')?.value;
    gcashBox.hidden=selected!=='GCash';
  }
  radios.forEach(r=>r.addEventListener('change',sync));
  paymentRadios.forEach(r=>r.addEventListener('change',syncPayment));
  sync();
  syncPayment();
})();
</script>
<?php if($editingOrder): ?><script>
(function(){const id=<?=json_encode($editingOrderId)?>;let stopped=false;async function check(){if(stopped)return;try{const r=await fetch('order-status.php?id='+encodeURIComponent(id),{cache:'no-store'});if(!r.ok)return;const d=await r.json();if(d.ok&&d.status!=='Pending'){stopped=true;document.querySelectorAll('form input,form textarea,form button').forEach(el=>el.disabled=true);const box=document.createElement('div');box.className='alert error';box.textContent='This order changed to '+d.status+'. Returning to My Purchase...';document.querySelector('main')?.prepend(box);setTimeout(()=>location.href='orders.php',900);}}catch(e){}}check();setInterval(check,1000);})();
</script><?php endif; ?>
<?php require 'config/footer.php'; ?>
