<?php
require 'config/database.php';
$stmt = $pdo->query("SELECT id, username, password_hash FROM users WHERE username = 'admin'");
$u = $stmt->fetch(PDO::FETCH_ASSOC);
echo "verify admin: " . (password_verify('admin', $u['password_hash']) ? 'yes' : 'no') . "\n";
echo "verify admin123: " . (password_verify('admin123', $u['password_hash']) ? 'yes' : 'no') . "\n";
echo "verify password: " . (password_verify('password', $u['password_hash']) ? 'yes' : 'no') . "\n";
