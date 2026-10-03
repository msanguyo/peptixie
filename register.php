<?php
require_once 'config/auth.php'; if(is_logged_in()) redirect('index.php'); $error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $name=trim($_POST['full_name']??''); $phone=trim($_POST['phone']??''); $telegram=trim($_POST['telegram_name']??''); $password=$_POST['password']??'';
 if(!$name||!$phone||!$telegram||strlen($password)<6) $error='Complete all fields. Password must be at least 6 characters.';
 else { $check=$pdo->prepare("SELECT id FROM users WHERE phone=?"); $check->execute([$phone]);
   if($check->fetch()) $error='Phone number is already registered.';
   else { $stmt=$pdo->prepare("INSERT INTO users(full_name,email,phone,telegram_name,address,password_hash) VALUES(?,NULL,?,?,NULL,?)"); $stmt->execute([$name,$phone,$telegram,password_hash($password,PASSWORD_DEFAULT)]); flash('success','Registration successful. You can now log in.'); redirect('login.php'); }
 }
}
$page_title='Register — PEPTIXIE'; require 'config/header.php'; ?>
<div class="auth-card"><div class="auth-brand small">PEPTIXIE 💉</div><h1>Create Account</h1><?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label>Full Name<input name="full_name" required></label><label>Phone Number<input name="phone" inputmode="tel" required></label>
<label>Telegram / WhatsApp / Viber Name<input name="telegram_name" placeholder="Your name or username" required></label>
<label>Password<input type="password" name="password" minlength="6" required></label>
<button class="btn btn-block">Register</button></form><p>Already registered? <a href="login.php">Login</a></p></div>
<?php require 'config/footer.php'; ?>