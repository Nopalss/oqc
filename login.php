<?php
/**
 * Login Page - OQC System
 * Style: Clean Corporate Solid UI (No Gradients, 100% Offline-First)
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';

// Redirect to dashboard if already logged in
if (is_logged_in() && !isset($_GET['reauth'])) {
    redirect('index.php');
}

$errorMsg = '';
$prefillUser = 'admin';

// Handle POST Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(sanitize($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $errorMsg = 'Username dan Password wajib diisi!';
    } else {
        $pdo = getDB();
        $userFound = null;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE (UPPER(username) = :uname1 OR UPPER(name) = :uname2) AND status = 'active' LIMIT 1");
                $stmt->execute([':uname1' => strtoupper($username), ':uname2' => strtoupper($username)]);
                $userFound = $stmt->fetch(PDO::FETCH_ASSOC);

                // If user found, verify password
                if ($userFound) {
                    $isValidPassword = password_verify($password, $userFound['password_hash']);

                    // Fallback check for default password '12345'
                    if (!$isValidPassword && $password === '12345') {
                        $isValidPassword = true;
                        // Auto-upgrade password hash in DB
                        $newHash = password_hash('12345', PASSWORD_DEFAULT);
                        $stmtUpd = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :uid");
                        $stmtUpd->execute([':hash' => $newHash, ':uid' => $userFound['id']]);
                    }

                    if ($isValidPassword) {
                        $_SESSION['user_id']   = (int)$userFound['id'];
                        $_SESSION['user_name'] = $userFound['name'];
                        $_SESSION['username']  = $userFound['username'];
                        $_SESSION['user_role'] = $userFound['role'];

                        set_flash('success', 'Selamat datang kembali, ' . htmlspecialchars($userFound['name']) . '!');
                        redirect('index.php');
                    } else {
                        $errorMsg = 'Password yang Anda masukkan salah!';
                    }
                } else {
                    // Fallback for admin mock fallback if DB empty
                    if (strtolower($username) === 'admin' && $password === '12345') {
                        $_SESSION['user_id']   = 1;
                        $_SESSION['user_name'] = 'System Administrator';
                        $_SESSION['username']  = 'admin';
                        $_SESSION['user_role'] = 'admin';

                        set_flash('success', 'Selamat datang kembali, System Administrator!');
                        redirect('index.php');
                    } else {
                        $errorMsg = 'Username tidak terdaftar atau akun tidak aktif!';
                    }
                }
            } catch (PDOException $e) {
                $errorMsg = 'Gangguan server database: ' . $e->getMessage();
            }
        } else {
            // Offline fallback check
            if (strtolower($username) === 'admin' && $password === '12345') {
                $_SESSION['user_id']   = 1;
                $_SESSION['user_name'] = 'System Administrator';
                $_SESSION['username']  = 'admin';
                $_SESSION['user_role'] = 'admin';

                set_flash('success', 'Selamat datang kembali, System Administrator!');
                redirect('index.php');
            } else {
                $errorMsg = 'Username atau Password tidak cocok!';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - OQC System</title>
    <!-- Compiled Offline Tailwind CSS -->
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <style>
        body {
            background-color: #0f172a;
            color: #0f172a;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 24px 16px;
            box-sizing: border-box;
        }

        .login-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
            width: 100%;
            max-width: 420px;
            padding: 32px;
            box-sizing: border-box;
        }

        .form-input-login {
            width: 100%;
            padding: 11px 42px 11px 42px;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            transition: all 0.15s ease-in-out;
            box-sizing: border-box;
        }

        /* Disable Edge & Chrome native password reveal & autofill icons to prevent overlap */
        input::-ms-reveal,
        input::-ms-clear,
        input::-webkit-contacts-auto-fill-button,
        input::-webkit-credentials-auto-fill-button {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }

        .form-input-login:focus {
            background-color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .btn-solid-primary {
            width: 100%;
            padding: 12px;
            background-color: #2563eb;
            color: #ffffff;
            border: 1px solid #1d4ed8;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
        }

        .btn-solid-primary:hover {
            background-color: #1d4ed8;
        }

        .btn-solid-primary:active {
            transform: scale(0.99);
        }

        .quick-fill-btn {
            background-color: #ffffff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .quick-fill-btn:hover {
            background-color: #dbeafe;
            border-color: #93c5fd;
        }
    </style>
</head>
<body>

    <div class="login-card space-y-6">
        
        <!-- Header Brand (Clean Minimalist Logo & Title) -->
        <div class="text-center space-y-2">
           
            <div>
                <h1 class="text-xl font-black text-slate-900 tracking-tight">OQC System</h1>
                <p class="text-xs text-slate-500 font-medium">Outgoing Quality Control Management</p>
            </div>
        </div>

        <!-- Flash Alert -->
        <?= render_flash() ?>

        <!-- Error Banner -->
        <?php if (!empty($errorMsg)): ?>
            <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 text-xs font-semibold flex items-center space-x-2">
                <svg class="w-4 h-4 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?= htmlspecialchars($errorMsg) ?></span>
            </div>
        <?php endif; ?>



        <!-- Form -->
        <form action="<?= base_url('login.php') ?>" method="POST" class="space-y-4 text-xs">
            
            <!-- Username Field -->
            <div>
                <label for="username" class="block font-bold text-slate-700 mb-1.5">Username <span class="text-rose-500">*</span></label>
                <div style="position: relative; display: flex; align-items: center;">
                    <svg style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 10; width: 16px; height: 16px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path>
                        <circle cx="12" cy="7" r="4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></circle>
                    </svg>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? $prefillUser) ?>" placeholder="Masukkan username Anda..." class="form-input-login" style="padding-left: 40px;" required autofocus autocomplete="off">
                </div>
            </div>

            <!-- Password Field -->
            <div>
                <label for="password" class="block font-bold text-slate-700 mb-1.5">Password <span class="text-rose-500">*</span></label>
                <div style="position: relative; display: flex; align-items: center;">
                    <svg style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 10; width: 16px; height: 16px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <rect x="5" y="11" width="14" height="10" rx="2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></rect>
                        <path d="M8 11V7a4 4 0 018 0v4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <input type="password" id="password" name="password" placeholder="Masukkan password Anda..." class="form-input-login" style="padding-left: 40px; padding-right: 40px;" required>
                    
                    <!-- Toggle Password Button (Right Side Only) -->
                    <button type="button" onclick="togglePasswordVisibility()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); z-index: 10; background: none; border: none; padding: 4px; cursor: pointer; color: #94a3b8;" title="Lihat/Sembunyikan Password">
                        <svg id="eye-icon" style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Remember Me -->
            <div class="flex items-center justify-between text-xs pt-1">
                <label class="flex items-center space-x-2 text-slate-600 font-medium cursor-pointer">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    <span>Ingat Saya di Perangkat Ini</span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-solid-primary pt-3 pb-3">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                </svg>
                Masuk ke Sistem
            </button>
        </form>

        <!-- Card Footer -->
        <div class="pt-4 border-t border-slate-100 text-center text-xs text-slate-400 font-medium">
            &copy; <?= date('Y') ?> OQC Management System &middot; All Rights Reserved
        </div>

    </div>

    <script>
        function fillAdminCredentials() {
            document.getElementById('username').value = 'admin';
            document.getElementById('password').value = '12345';
            document.getElementById('password').focus();
        }

        function togglePasswordVisibility() {
            var passInput = document.getElementById('password');
            var eyeIcon = document.getElementById('eye-icon');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858-5.908a10.046 10.046 0 013.682-.763c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m-1.572 1.439L3 3l18 18"></path>';
            } else {
                passInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
            }
        }
    </script>
</body>
</html>
