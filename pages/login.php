<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../auth/csrf.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Task Check — Login</title>
    <script>document.documentElement.classList.add('login-entrance-pending');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/login.css?v=7">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="../scripts/theme.js?v=6"></script>
</head>

<body>
    <div class="auth-shell">

        <!-- Left: brand backdrop with a task-checklist hero visual -->
        <div class="auth-photo">
            <span class="deco-corner tl"></span>
            <span class="deco-corner br"></span>

            <div class="auth-photo-content">
                <!-- Floating task-checklist preview — what the app actually does -->
                <div class="task-preview-card">
                    <div class="task-preview-card-header">
                        <span class="task-preview-tag">Today's Tasks</span>
                        <span class="task-preview-badge"><i class="fas fa-qrcode"></i></span>
                    </div>
                    <div class="task-preview-row" id="taskRowUsername">
                        <span class="task-preview-check"><i class="fas fa-check"></i></span>
                        <span class="task-preview-label">Enter Username</span>
                    </div>
                    <div class="task-preview-row" id="taskRowPassword">
                        <span class="task-preview-check"><i class="fas fa-check"></i></span>
                        <span class="task-preview-label">Enter Password</span>
                    </div>
                </div>

                <div class="brand-mark-wrap">
                    <svg class="brand-mark" viewBox="0 0 120 120" fill="none">
                        <rect x="14" y="14" width="92" height="92" rx="20" stroke="white" stroke-width="6" />
                        <rect x="30" y="30" width="20" height="20" rx="5" fill="white" />
                        <rect x="70" y="30" width="20" height="20" rx="5" fill="white" />
                        <rect x="30" y="70" width="20" height="20" rx="5" fill="white" />
                        <path d="M66 68 L82 84 L100 58" stroke="white" stroke-width="7" stroke-linecap="round"
                            stroke-linejoin="round" fill="none" />
                    </svg>
                    <p class="brand-title">QR Task Check</p>
                    <p class="brand-tagline">Scan. Verify. Complete.</p>
                </div>
            </div>
        </div>

        <!-- White wave shape -->
        <div class="auth-card-rect"></div>
        <div class="auth-card-bump"></div>

        <!-- Mascot — sits right on the seam, centered on the bump cutout -->
        <div class="mascot-wrap">
            <div class="mascot" id="mascot">
                <span class="mascot-antenna left"></span>
                <span class="mascot-antenna right"></span>
                <div class="mascot-face">
                    <span class="mascot-eye left" id="mascotEyeLeft"></span>
                    <span class="mascot-eye right" id="mascotEyeRight"></span>
                </div>
            </div>
        </div>

        <!-- Login form -->
        <div class="auth-form">
            <h1>Welcome Back</h1>
            <p class="auth-subtitle">Log in to continue your task checks</p>

            <form id="loginForm" novalidate>
                <?= csrfField() ?>
                <div class="auth-field">
                    <label for="login_id">Biometrics Number</label>
                    <div class="auth-input">
                        <i class="fas fa-user"></i>
                        <input type="text" id="login_id" name="login_id" autocomplete="username" placeholder="Enter your biometrics number">
                    </div>
                </div>

                <div class="auth-field">
                    <label for="login_password">Password</label>
                    <div class="auth-input">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="login_password" name="login_password" autocomplete="current-password" placeholder="Enter your password">
                        <button type="button" class="eye-toggle-btn" id="eyeToggleBtn" onclick="togglePasswordVisibility()" aria-label="Show password" aria-pressed="false">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="auth-message" id="authMessage" role="alert"></div>

                <button type="submit" class="btn-login" id="loginSubmitBtn">Log In</button>
            </form>
        </div>

    </div>

    <script src="../scripts/login.js?v=2"></script>
</body>

</html>
