<?php
require_once 'config/auth.php';
header('Content-Type: application/json');
if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
$stmt=$pdo->prepare("SELECT id,order_number,status,total,updated_at FROM orders WHERE user_id=? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
echo json_encode(['orders'=>$stmt->fetchAll()]);
?>