<?php
http_response_code(403);
require_once __DIR__ . '/../auth/session.php';

// Dynamically compute the base URL for QRS_new so all assets, links, and styles
// load correctly regardless of whether the 403 was triggered from /pages/user_management.php,
// /pages/audit_logs.php, or directly from /pages/403.php
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$pagesDir = dirname($scriptName);
$appRoot = rtrim(dirname($pagesDir), '/\\');

if ($appRoot === '' || $appRoot === '.') {
    if (isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '/QRS_new')) {
        $appRoot = '/QRS_new';
    } else {
        $appRoot = '';
    }
}

$dashboardUrl = $appRoot . '/pages/dashboard.php';
$appCssUrl    = $appRoot . '/styles/app.css?v=16';
$themeJsUrl   = $appRoot . '/scripts/theme.js?v=6';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>403 — Access Denied · QR Task Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($appCssUrl) ?>">
    <script src="<?= htmlspecialchars($themeJsUrl) ?>"></script>
    <style>
        :root {
            --page-bg: #F0FAF6;
            --card-bg: #FFFFFF;
            --card-border: rgba(82, 122, 99, 0.15);
            --title-color: #1C3326;
            --subtitle-color: #4B6355;
            --tagline-color: #6D8878;
            --btn-bg: #4D725E;
            --btn-hover: #5A826C;
            --btn-bevel: #2F4D3C;
            --btn-text: #FFFFFF;
            --accent-amber: #F1A83B;
        }

        :root[data-theme="dark"] {
            --page-bg: #0D1511;
            --card-bg: #14201A;
            --card-border: rgba(143, 174, 153, 0.18);
            --title-color: #E6EFE9;
            --subtitle-color: #9CB7A6;
            --tagline-color: #7E9C89;
            --btn-bg: #527A63;
            --btn-hover: #639278;
            --btn-bevel: #263C30;
            --btn-text: #FFFFFF;
            --accent-amber: #E29B32;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--title-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
            overflow-x: hidden;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        /* Large, prominent container matching the 404 layout */
        .error-layout {
            width: 92%;
            max-width: 1200px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin: auto;
        }

        /* Top Header */
        .error-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14px;
            width: 100%;
        }

        .error-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--title-color);
            transition: opacity 0.2s ease;
        }

        .error-brand:hover {
            opacity: 0.85;
        }

        .error-brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #274635;
        }

        .error-brand-name {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--title-color);
        }

        .error-tagline {
            font-size: 14.5px;
            font-weight: 500;
            color: var(--tagline-color);
            letter-spacing: -0.01em;
        }

        /* Main Card - Big, spacious, and generous */
        .error-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 36px;
            box-shadow: 0 20px 50px -12px rgba(28, 51, 38, 0.09), 0 6px 18px -2px rgba(28, 51, 38, 0.04);
            padding: 56px 48px 64px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            transition: background-color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        /* Illustration Stage */
        .illustration-wrap {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto 28px;
            user-select: none;
            -webkit-user-select: none;
        }

        .illustration-svg {
            width: 100%;
            height: auto;
            max-height: 310px;
            display: block;
            margin: 0 auto;
        }

        /* Seamless Waving "No No No" Animation with Natural Expressive Cadence */
        @keyframes wagNoNo {
            0%, 100% {
                transform: rotate(0deg);
            }
            10% {
                transform: rotate(-15deg);
            }
            22% {
                transform: rotate(15deg);
            }
            34% {
                transform: rotate(-12deg);
            }
            46% {
                transform: rotate(12deg);
            }
            58% {
                transform: rotate(-5deg);
            }
            68% {
                transform: rotate(5deg);
            }
            76% {
                transform: rotate(0deg);
            }
        }

        /* Motion swish line animations */
        @keyframes swishLeftFade {
            0%, 76%, 100% {
                opacity: 0;
                transform: translateX(0);
            }
            10%, 34% {
                opacity: 0.9;
                transform: translateX(-4px);
            }
            22%, 46% {
                opacity: 0;
                transform: translateX(0);
            }
        }

        @keyframes swishRightFade {
            0%, 76%, 100% {
                opacity: 0;
                transform: translateX(0);
            }
            22%, 46% {
                opacity: 0.9;
                transform: translateX(4px);
            }
            10%, 34% {
                opacity: 0;
                transform: translateX(0);
            }
        }

        /* Ambient floating animations */
        @keyframes floatShield {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-6px);
            }
        }

        @keyframes floatLock {
            0%, 100% {
                transform: translateY(0px) rotate(0deg);
            }
            50% {
                transform: translateY(-7px) rotate(-4deg);
            }
        }

        @keyframes pulseBadge {
            0%, 100% {
                transform: scale(1);
                opacity: 0.95;
            }
            50% {
                transform: scale(1.05);
                opacity: 1;
            }
        }

        @keyframes diamondBob {
            0%, 100% {
                transform: translateY(0px) rotate(20deg);
            }
            50% {
                transform: translateY(-6px) rotate(24deg);
            }
        }

        .anim-waving-hand {
            transform-origin: 518px 248px;
            animation: wagNoNo 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        .anim-swish-left {
            animation: swishLeftFade 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        .anim-swish-right {
            animation: swishRightFade 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        .anim-shield {
            transform-origin: 170px 140px;
            animation: floatShield 4.2s ease-in-out infinite;
        }

        .anim-lock {
            transform-origin: 820px 140px;
            animation: floatLock 4s ease-in-out infinite 0.6s;
        }

        .anim-badge-pulse {
            transform-origin: 170px 145px;
            animation: pulseBadge 3s ease-in-out infinite;
        }

        .anim-diamond-amber {
            transform-origin: center;
            animation: diamondBob 4.6s ease-in-out infinite 0.7s;
        }

        @media (prefers-reduced-motion: reduce) {
            .anim-waving-hand,
            .anim-swish-left,
            .anim-swish-right,
            .anim-shield,
            .anim-lock,
            .anim-badge-pulse,
            .anim-diamond-amber {
                animation: none !important;
            }
        }

        /* Content & Copy */
        .error-content {
            max-width: 620px;
            margin: 0 auto;
        }

        .error-title {
            font-size: 38px;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--title-color);
            margin-bottom: 14px;
            line-height: 1.2;
        }

        .error-subtitle {
            font-size: 17px;
            line-height: 1.65;
            color: var(--subtitle-color);
            margin-bottom: 34px;
            font-weight: 400;
        }

        /* Strictly Followed "Back to Dashboard" Button */
        .btn-dashboard {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background-color: var(--btn-bg);
            color: var(--btn-text);
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 600;
            padding: 14px 40px;
            border-radius: 14px;
            text-decoration: none;
            box-shadow: 0 5px 0 var(--btn-bevel), 0 8px 20px rgba(44, 70, 54, 0.25);
            transition: all 0.16s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            outline: none;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-dashboard:hover {
            background-color: var(--btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 7px 0 var(--btn-bevel), 0 12px 26px rgba(44, 70, 54, 0.32);
            color: var(--btn-text);
        }

        .btn-dashboard:active {
            transform: translateY(3px);
            box-shadow: 0 2px 0 var(--btn-bevel), 0 4px 10px rgba(44, 70, 54, 0.2);
            color: var(--btn-text);
        }

        .btn-dashboard:focus-visible {
            outline: 2px solid #527A63;
            outline-offset: 3px;
        }

        .btn-arrow {
            font-size: 18px;
            line-height: 1;
            transition: transform 0.2s ease;
        }

        .btn-dashboard:hover .btn-arrow {
            transform: translateX(-3px);
        }

        @media (max-width: 768px) {
            body {
                padding: 18px 12px;
            }

            .error-layout {
                width: 96%;
            }

            .error-card {
                padding: 38px 20px 44px;
                border-radius: 24px;
            }

            .error-header {
                flex-direction: column;
                gap: 6px;
                align-items: center;
                text-align: center;
                padding-bottom: 4px;
            }

            .error-title {
                font-size: 28px;
            }

            .error-subtitle {
                font-size: 15px;
                margin-bottom: 26px;
            }

            .btn-dashboard {
                width: 100%;
                padding: 14px 24px;
            }
        }
    </style>
</head>

<body>
    <div class="error-layout">
        <!-- Topbar Brand -->
        <header class="error-header">
            <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="error-brand" title="QR Task Check">
                <span class="error-brand-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <rect x="2" y="2" width="8" height="8" rx="2" />
                        <rect x="14" y="2" width="8" height="8" rx="2" />
                        <rect x="2" y="14" width="8" height="8" rx="2" />
                        <rect x="14" y="14" width="8" height="8" rx="2" />
                    </svg>
                </span>
                <span class="error-brand-name">QR Task Check</span>
            </a>
            <span class="error-tagline">Scan. Track. Get it done.</span>
        </header>

        <!-- Main Card -->
        <main class="error-card">
            <div class="illustration-wrap">
                <svg class="illustration-svg" viewBox="0 0 1000 280" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="403 Forbidden Access Denied Illustration">
                    <defs>
                        <!-- Ground Shadow Gradient -->
                        <radialGradient id="groundShadowGrad" cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stop-color="#4A755A" stop-opacity="0.14" />
                            <stop offset="100%" stop-color="#4A755A" stop-opacity="0" />
                        </radialGradient>

                        <!-- Shield Glow Gradient -->
                        <linearGradient id="shieldGrad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#FFFFFF" />
                            <stop offset="100%" stop-color="#EAF3ED" />
                        </linearGradient>

                        <!-- Hand Skin Tone Gradient (Matching Reference Image) -->
                        <linearGradient id="handSkinGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#FCE4CC" />
                            <stop offset="50%" stop-color="#F9CDA2" />
                            <stop offset="100%" stop-color="#F3BD8D" />
                        </linearGradient>

                        <!-- Amber Accent Gradient -->
                        <linearGradient id="amberGrad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#F6BC56" />
                            <stop offset="100%" stop-color="#E29424" />
                        </linearGradient>
                    </defs>

                    <!-- Ground Shadows -->
                    <ellipse cx="170" cy="235" rx="65" ry="10" fill="url(#groundShadowGrad)" />
                    <ellipse cx="518" cy="254" rx="75" ry="10" fill="url(#groundShadowGrad)" />
                    <ellipse cx="830" cy="235" rx="75" ry="10" fill="url(#groundShadowGrad)" />

                    <!-- Decorative Foliage (Left) -->
                    <path d="M105 230 C95 204 112 184 124 198 C130 172 154 176 158 208 C168 192 184 196 180 230 Z" fill="#B5D9C4" />
                    <path d="M116 230 C112 212 124 198 132 210 C142 192 158 196 160 222 Z" fill="#98C4AB" />

                    <!-- Decorative Foliage (Right) -->
                    <path d="M850 230 C840 204 856 185 868 200 C875 178 898 182 900 212 C910 196 926 200 922 230 Z" fill="#B5D9C4" />
                    <path d="M860 230 C857 212 870 198 880 210 C890 192 906 196 908 222 Z" fill="#98C4AB" />

                    <!-- LEFT SIDE: Security Access Pedestal & Shield -->
                    <g id="securityPedestal" class="anim-shield">
                        <!-- Pedestal Base -->
                        <ellipse cx="170" cy="215" rx="38" ry="8" fill="#274635" />
                        <path d="M152 215 L160 155 C161 150 165 146 170 146 C175 146 179 150 180 155 L188 215 Z" fill="#FFFFFF" stroke="#D3E0D7" stroke-width="1.6" />
                        <path d="M152 215 L160 155 C161 150 165 146 168 146 L174 215 Z" fill="#E8F1EC" />

                        <!-- Security Shield Emblem -->
                        <g transform="translate(170, 115)">
                            <path d="M0 -36 C22 -36 34 -24 34 -8 C34 18 14 36 0 44 C-14 36 -34 18 -34 -8 C-34 -24 -22 -36 0 -36 Z" fill="url(#shieldGrad)" stroke="#C4D7CC" stroke-width="2.2" />
                            <path d="M0 -28 C16 -28 26 -18 26 -6 C26 14 10 28 0 34 C-10 28 -26 14 -26 -6 C-26 -18 -16 -28 0 -28 Z" fill="#F4FAF6" stroke="#D3E2D8" stroke-width="1.2" />
                            
                            <!-- Lock Shackle & Body -->
                            <rect x="-10" y="-4" width="20" height="18" rx="4" fill="#274635" />
                            <path d="M-6 -4 L-6 -10 C-6 -14 -3 -17 0 -17 C3 -17 6 -14 6 -10 L6 -4" fill="none" stroke="#274635" stroke-width="3" stroke-linecap="round" />
                            <circle cx="0" cy="3" r="2.5" fill="#FFFFFF" />
                            <line x1="0" y1="4" x2="0" y2="9" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" />
                        </g>

                        <!-- Glowing Amber Restriction Dot -->
                        <circle class="anim-badge-pulse" cx="170" cy="56" r="6" fill="url(#amberGrad)" />
                        <circle cx="170" cy="56" r="11" fill="none" stroke="#F1A83B" stroke-width="1.5" stroke-opacity="0.4" />
                    </g>

                    <!-- CENTER: "4" on left, "3" on right -->
                    <g id="numbers403">
                        <!-- Left "4" (Starts 275, Ends 352) -->
                        <path d="M320 165 L352 165 L352 187 L335 187 L335 207 L312 207 L312 187 L275 187 L275 165 L312 108 L335 108 L335 165 Z M312 165 L312 137 L292 165 Z" fill="#274635" />

                        <!-- Right "3" (Starts 648, Ends 725) -->
                        <path d="M650 110 L715 110 L715 132 L680 144 C698 147 722 156 722 178 C722 198 704 210 678 210 C655 210 642 198 640 186 L664 182 C665 188 670 193 678 193 C688 193 695 187 695 178 C695 168 686 162 672 162 L662 162 L662 144 L676 138 C685 135 690 130 690 125 C690 121 686 118 680 118 L650 118 Z" fill="#274635" />
                    </g>

                    <!-- Ambient Center Shield Portal behind the waving hand -->
                    <g transform="translate(500, 150)">
                        <rect x="-70" y="-80" width="140" height="150" rx="28" fill="#F4FAF6" stroke="#D3E5DA" stroke-width="2" />
                        <rect x="-56" y="-66" width="112" height="122" rx="20" fill="#FFFFFF" stroke="#E2EDE6" stroke-width="1.2" />
                        <!-- Subtle keyhole watermark -->
                        <circle cx="0" cy="-15" r="16" fill="#E8F2EC" />
                        <path d="M-9 -10 L9 -10 L14 18 L-14 18 Z" fill="#E8F2EC" />
                    </g>

                    <!-- Motion Swish Curves (2 on left, 2 on right - Matching Reference Image) -->
                    <g class="anim-swish-left" transform="translate(420, 8)">
                        <!-- Outer Left Arc -->
                        <path d="M 38 28 C 36 45, 40 68, 54 88" stroke="#274635" stroke-width="3.2" stroke-linecap="round" fill="none" />
                        <!-- Inner Left Arc -->
                        <path d="M 55 41 C 52 54, 56 68, 64 78" stroke="#274635" stroke-width="2.5" stroke-linecap="round" fill="none" />
                    </g>
                    <g class="anim-swish-right" transform="translate(420, 8)">
                        <!-- Inner Right Arc -->
                        <path d="M 122 38 C 124 48, 122 58, 117 68" stroke="#274635" stroke-width="2.5" stroke-linecap="round" fill="none" />
                        <!-- Outer Right Arc -->
                        <path d="M 138 28 C 142 45, 138 65, 128 82" stroke="#274635" stroke-width="3.2" stroke-linecap="round" fill="none" />
                    </g>

                    <!-- CENTERPIECE: Custom Illustrated Waving Hand ("No No No") - Exact Copy of Reference Image -->
                    <g class="anim-waving-hand" id="wavingHand">
                        <g transform="translate(420, 8)">
                            <!-- Hand Base Silhouette & Palm Fill -->
                            <path d="M 78 95
                                     C 77 72, 78 45, 83 33
                                     C 87 23, 100 23, 104 33
                                     C 109 45, 111 75, 114 112
                                     C 122 118, 135 130, 143 146
                                     C 152 163, 154 182, 152 200
                                     C 149 216, 138 234, 122 244
                                     C 114 247, 107 248, 98 233
                                     C 92 242, 85 248, 74 248
                                     C 52 248, 22 230, 14 208
                                     C 7 192, 4 176, 3 160
                                     C 2 150, 6 140, 15 138
                                     C 18 137, 24 140, 27 147
                                     C 28 135, 34 122, 46 122
                                     C 53 122, 57 127, 59 135
                                     C 60 118, 66 103, 78 103
                                     Z"
                                  fill="url(#handSkinGrad)" stroke="#CB874C" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />

                            <!-- Curled Finger Creases (Internal Lines) -->
                            <!-- Middle Finger Crease -->
                            <path d="M 78 103 C 77 114, 76 124, 72 134" fill="none" stroke="#CB874C" stroke-width="2.8" stroke-linecap="round" />
                            <!-- Ring Finger Crease -->
                            <path d="M 52 122 C 50 134, 48 144, 46 152" fill="none" stroke="#CB874C" stroke-width="2.8" stroke-linecap="round" />
                            <!-- Pinky Finger Crease -->
                            <path d="M 27 138 C 26 150, 25 160, 24 168" fill="none" stroke="#CB874C" stroke-width="2.8" stroke-linecap="round" />

                            <!-- Thumb (Sweeping across front of fingers) -->
                            <path d="M 114 112 
                                     C 98 120, 84 134, 68 137 
                                     C 54 139, 47 147, 52 157 
                                     C 56 166, 68 168, 82 165 
                                     C 96 162, 108 154, 116 144" 
                                  fill="url(#handSkinGrad)" stroke="#CB874C" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />

                            <!-- Palm Crease in bottom cleft -->
                            <path d="M 98 233 C 99 224, 101 216, 102 210" fill="none" stroke="#CB874C" stroke-width="2.6" stroke-linecap="round" />

                            <!-- Lifeline / Palm Creases in Center -->
                            <path d="M 64 196 C 73 208, 81 212, 86 210 C 93 206, 97 195, 99 180" fill="none" stroke="#CB874C" stroke-width="2.6" stroke-linecap="round" />
                            <path d="M 80 206 C 86 198, 92 188, 95 174" fill="none" stroke="#CB874C" stroke-width="2.2" stroke-linecap="round" />
                        </g>
                    </g>

                    <!-- Dashed Security Trail across top -->
                    <path d="M410 48 C470 22, 530 22, 590 48 C650 74, 700 105, 730 130" stroke="#9BB8A7" stroke-width="2.6" stroke-dasharray="6 6" stroke-linecap="round" />

                    <!-- Floating Tilted Security Modules on the Arch -->
                    <!-- Green Diamond -->
                    <g transform="translate(440, 32) rotate(15)">
                        <rect x="0" y="0" width="18" height="18" rx="3.5" fill="#274635" />
                        <rect x="3.5" y="3.5" width="11" height="11" rx="2" fill="#FFFFFF" />
                    </g>
                    <!-- Amber Diamond (Animated) -->
                    <g class="anim-diamond-amber" transform="translate(565, 32) rotate(20)">
                        <rect x="0" y="0" width="20" height="20" rx="4" fill="url(#amberGrad)" />
                        <rect x="4" y="4" width="12" height="12" rx="2" fill="#FFFFFF" />
                    </g>

                    <!-- RIGHT SIDE: Security Clearance / Access Badge & Lock -->
                    <g id="securityBadgeRight" class="anim-lock" transform="translate(775, 120)">
                        <!-- Folded Access Clipboard / Clearance Dossier -->
                        <polygon points="10,74 54,52 106,68 150,46 144,108 100,130 48,114 6,136" fill="#E4ECE7" stroke="#BACDC1" stroke-width="2" />
                        <polygon points="54,52 106,68 100,130 48,114" fill="#D3E2D8" stroke="#BACDC1" stroke-width="2" />

                        <!-- ID Badge Card on Clipboard -->
                        <g transform="translate(70, 10)">
                            <rect x="0" y="0" width="48" height="62" rx="5" fill="#FFFFFF" stroke="#C4D7CC" stroke-width="1.8" />
                            <!-- User Silhouette on Badge -->
                            <circle cx="16" cy="18" r="7" fill="#E2EDE6" />
                            <path d="M7 32 C7 27 11 25 16 25 C21 25 25 27 25 32 Z" fill="#BACDC1" />
                            
                            <!-- Red/Amber "ADMIN ONLY" Restriction Tag -->
                            <rect x="6" y="38" width="36" height="12" rx="3" fill="#FFF2DE" stroke="#E29424" stroke-width="1.2" />
                            <text x="24" y="47" font-family="'Outfit', sans-serif" font-size="7" font-weight="700" fill="#B46E0E" text-anchor="middle" letter-spacing="0.02em">ADMIN</text>

                            <!-- Mini QR Code on Badge -->
                            <rect x="29" y="11" width="13" height="13" rx="2" fill="#274635" />
                            <rect x="31.5" y="13.5" width="8" height="8" rx="1" fill="#FFFFFF" />
                            <rect x="33.5" y="15.5" width="4" height="4" rx="0.5" fill="#274635" />
                        </g>

                        <!-- Floating Golden Padlock -->
                        <g transform="translate(30, 65)">
                            <rect x="0" y="10" width="26" height="22" rx="5" fill="url(#amberGrad)" stroke="#B46E0E" stroke-width="1.5" />
                            <!-- Shackle -->
                            <path d="M6 10 L6 3 C6 -2 10 -6 13 -6 C16 -2 20 -2 20 3 L20 10" fill="none" stroke="#7A8E82" stroke-width="3" stroke-linecap="round" />
                            <circle cx="13" cy="19" r="2.5" fill="#274635" />
                            <line x1="13" y1="20" x2="13" y2="25" stroke="#274635" stroke-width="2" stroke-linecap="round" />
                        </g>
                    </g>
                </svg>
            </div>

            <div class="error-content">
                <h1 class="error-title">Access Denied</h1>
                <p class="error-subtitle">You don’t have permission to access this page. This area is strictly reserved for system administrators.</p>
                <!-- Real, Functional, Solid 3D Button strictly matching mockup -->
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn-dashboard">
                    <span class="btn-arrow">←</span>
                    <span>Back to Dashboard</span>
                </a>
            </div>
        </main>
    </div>
</body>

</html>
