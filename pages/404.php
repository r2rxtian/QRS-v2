<?php
http_response_code(404);
require_once __DIR__ . '/../auth/session.php';

// Dynamically compute the QRS base URL so all assets, links, and styles
// load correctly regardless of whether the 404 was triggered from /taskz.php,
// /pages/taskz.php, or a deeply nested /some/missing/path/
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$pagesDir = dirname($scriptName);
$appRoot = rtrim(dirname($pagesDir), '/\\');

if ($appRoot === '' || $appRoot === '.') {
    if (isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '/QRTS')) {
        $appRoot = '/QRTS';
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
    <title>404 — Page Not Found · QR Task Check</title>
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

        /* Expansive, prominent container matching mockup */
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

        /* Animations */
        @keyframes scanPulse {
            0%, 100% {
                opacity: 0.38;
                transform: scaleY(1);
            }
            50% {
                opacity: 0.75;
                transform: scaleY(1.03);
            }
        }

        @keyframes floatBob1 {
            0%, 100% {
                transform: translateY(0px) rotate(-14deg);
            }
            50% {
                transform: translateY(-8px) rotate(-10deg);
            }
        }

        @keyframes floatBob2 {
            0%, 100% {
                transform: translateY(0px) rotate(12deg);
            }
            50% {
                transform: translateY(-9px) rotate(16deg);
            }
        }

        @keyframes floatBob3 {
            0%, 100% {
                transform: translateY(0px) rotate(-10deg);
            }
            50% {
                transform: translateY(-7px) rotate(-6deg);
            }
        }

        @keyframes floatBob4 {
            0%, 100% {
                transform: translateY(0px) rotate(14deg);
            }
            50% {
                transform: translateY(-9px) rotate(18deg);
            }
        }

        @keyframes floatBob5 {
            0%, 100% {
                transform: translateY(0px) rotate(-8deg);
            }
            50% {
                transform: translateY(-7px) rotate(-4deg);
            }
        }

        @keyframes pinBob {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-6px);
            }
        }

        @keyframes sparklePulse {
            0%, 100% {
                opacity: 0.7;
                transform: scale(0.96);
            }
            50% {
                opacity: 1;
                transform: scale(1.08);
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

        .anim-beam {
            transform-origin: 220px 105px;
            animation: scanPulse 3.2s ease-in-out infinite;
        }

        .anim-chip-1 {
            animation: floatBob1 4.5s ease-in-out infinite;
        }

        .anim-chip-2 {
            animation: floatBob2 5s ease-in-out infinite 0.5s;
        }

        .anim-chip-3 {
            animation: floatBob3 4.2s ease-in-out infinite 1s;
        }

        .anim-chip-4 {
            animation: floatBob4 4.8s ease-in-out infinite 0.3s;
        }

        .anim-chip-5 {
            animation: floatBob5 4.6s ease-in-out infinite 0.8s;
        }

        .anim-pin {
            animation: pinBob 3.5s ease-in-out infinite;
        }

        .anim-sparkles {
            transform-origin: 500px 95px;
            animation: sparklePulse 2.8s ease-in-out infinite;
        }

        .anim-diamond-yellow {
            animation: diamondBob 4.6s ease-in-out infinite 0.7s;
        }

        @media (prefers-reduced-motion: reduce) {
            .anim-beam,
            .anim-chip-1,
            .anim-chip-2,
            .anim-chip-3,
            .anim-chip-4,
            .anim-chip-5,
            .anim-pin,
            .anim-sparkles,
            .anim-diamond-yellow {
                animation: none !important;
            }
        }

        /* Content & Copy - Generously spaced */
        .error-content {
            max-width: 600px;
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
                <svg class="illustration-svg" viewBox="0 0 1000 280" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="404 Scanner Illustration">
                    <defs>
                        <!-- Scanner Beam Gradient -->
                        <linearGradient id="scanBeamGrad" x1="220" y1="105" x2="370" y2="120" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#55C789" stop-opacity="0.45" />
                            <stop offset="55%" stop-color="#8AD3A4" stop-opacity="0.22" />
                            <stop offset="100%" stop-color="#C2EAD2" stop-opacity="0.03" />
                        </linearGradient>

                        <!-- Emerald Lens Gradient -->
                        <linearGradient id="lensGrad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#7AE8AA" />
                            <stop offset="100%" stop-color="#4ABF7E" />
                        </linearGradient>

                        <!-- Ground Shadow Gradient -->
                        <radialGradient id="groundShadowGrad" cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stop-color="#4A755A" stop-opacity="0.14" />
                            <stop offset="100%" stop-color="#4A755A" stop-opacity="0" />
                        </radialGradient>
                    </defs>

                    <!-- Ground Shadows -->
                    <ellipse cx="140" cy="235" rx="55" ry="10" fill="url(#groundShadowGrad)" />
                    <ellipse cx="800" cy="230" rx="85" ry="12" fill="url(#groundShadowGrad)" />

                    <!-- Decorative Foliage / Bushes (Left) -->
                    <path d="M85 230 C75 204 92 184 104 198 C110 172 134 176 138 208 C148 192 164 196 160 230 Z" fill="#B5D9C4" />
                    <path d="M96 230 C92 212 104 198 112 210 C122 192 138 196 140 222 Z" fill="#98C4AB" />

                    <!-- Decorative Foliage / Bushes (Right) -->
                    <path d="M860 230 C850 204 866 185 878 200 C885 178 908 182 910 212 C920 196 936 200 932 230 Z" fill="#B5D9C4" />
                    <path d="M870 230 C867 212 880 198 890 210 C900 192 916 196 918 222 Z" fill="#98C4AB" />

                    <!-- Scanning Light Beam (Animated) -->
                    <polygon class="anim-beam" points="220,95 370,55 370,205 220,115" fill="url(#scanBeamGrad)" />

                    <!-- Ergonomic Handheld QR Scanner Device (Left) -->
                    <g id="scannerDevice">
                        <!-- Handle Body (White / light gray with soft ergonomic contour) -->
                        <path d="M112 230 L158 114 C161 106 170 104 178 109 L192 118 C198 123 199 132 194 140 L146 235 C143 242 132 245 125 240 L116 235 C110 233 110 230 112 230 Z" fill="#FFFFFF" stroke="#D3E0D7" stroke-width="1.8" />
                        
                        <!-- Handle Soft Shadow Contour -->
                        <path d="M112 230 L158 114 C161 106 166 104 171 106 L158 142 L130 233 C125 239 118 236 112 230 Z" fill="#E8F1EC" />

                        <!-- Dark Green Bottom Rubber Boot -->
                        <path d="M108 225 L140 225 C147 225 152 230 149 238 C147 244 139 248 130 248 L114 248 C105 248 99 243 101 237 C102 231 105 225 108 225 Z" fill="#274635" />

                        <!-- Dark Green Trigger Switch (front of handle under head) -->
                        <rect x="172" y="132" width="12" height="24" rx="6" fill="#274635" transform="rotate(-22 172 132)" />

                        <!-- Scanner Head Body (White, sleek, forward-sloping) -->
                        <path d="M118 88 C118 60 142 48 174 53 L216 65 C228 69 233 81 228 93 L216 124 C211 136 199 141 187 136 L154 124 C136 117 118 103 118 88 Z" fill="#FFFFFF" stroke="#D3E0D7" stroke-width="1.8" />
                        
                        <!-- Head Top Shading -->
                        <path d="M136 58 C160 51 198 60 216 65 L211 88 C192 78 158 69 136 74 Z" fill="#F4F8F6" />

                        <!-- Rear Cap Accent (Dark Green, wrapping around the back curve) -->
                        <path d="M130 56 C118 61 111 73 111 87 C111 101 121 113 133 118 L138 108 C128 103 121 96 121 87 C121 76 126 68 135 63 Z" fill="#274635" />

                        <!-- Front Bezel Ring (Dark Green Oval Ring) -->
                        <ellipse cx="220" cy="105" rx="15" ry="28" fill="#274635" transform="rotate(-22 220 105)" />

                        <!-- Emerald Scanner Lens (Bright Green Window) -->
                        <ellipse cx="220" cy="105" rx="10" ry="23" fill="url(#lensGrad)" transform="rotate(-22 220 105)" />

                        <!-- Grass Tufts near scanner base -->
                        <path d="M172 235 L176 226 M176 235 L182 224 M181 235 L186 228" stroke="#98C4AB" stroke-width="2.2" stroke-linecap="round" />
                    </g>

                    <!-- Floating Mini QR Code Chips STRICTLY in the middle between Scanner and 404 -->
                    <!-- Chip 1 (bottom of beam, between scanner and 404) -->
                    <g transform="translate(265, 185)">
                        <g class="anim-chip-1">
                            <rect x="-12" y="-12" width="24" height="24" rx="4.5" fill="#274635" />
                            <rect x="-8" y="-8" width="16" height="16" rx="2.5" fill="#FFFFFF" />
                            <rect x="-4" y="-4" width="8" height="8" rx="1.5" fill="#274635" />
                        </g>
                    </g>

                    <!-- Chip 2 (middle of beam, between scanner and 404) -->
                    <g transform="translate(305, 138)">
                        <g class="anim-chip-2">
                            <rect x="-11" y="-11" width="22" height="22" rx="4" fill="#274635" />
                            <rect x="-7" y="-7" width="14" height="14" rx="2" fill="#FFFFFF" />
                            <rect x="-3.5" y="-3.5" width="7" height="7" rx="1" fill="#274635" />
                        </g>
                    </g>

                    <!-- Chip 3 (top of beam, between scanner and 404) -->
                    <g transform="translate(345, 82)">
                        <g class="anim-chip-3">
                            <rect x="-10.5" y="-10.5" width="21" height="21" rx="3.5" fill="#274635" />
                            <rect x="-7" y="-7" width="14" height="14" rx="2" fill="#FFFFFF" />
                            <rect x="-3.5" y="-3.5" width="7" height="7" rx="1" fill="#274635" />
                        </g>
                    </g>

                    <!-- Center "404" Group - EXACTLY IN THE HORIZONTAL MIDDLE (Midpoint = 500px) -->
                    <g id="errorNumber">
                        <!-- First "4" (Starts 357, Ends 434) -->
                        <path d="M402 165 L434 165 L434 187 L417 187 L417 207 L394 207 L394 187 L357 187 L357 165 L394 108 L417 108 L417 165 Z M394 165 L394 137 L374 165 Z" fill="#274635" />

                        <!-- Center "0" as Stylized QR Code Target (Starts 458, Ends 542 - Center is 500px!) -->
                        <g transform="translate(458, 108)">
                            <!-- Outer QR Frame -->
                            <rect x="0" y="0" width="84" height="98" rx="18" fill="#274635" />
                            <!-- Inner White Cutout -->
                            <rect x="18" y="18" width="48" height="62" rx="7" fill="#FFFFFF" />
                            <!-- Center Dark Square -->
                            <rect x="29" y="29" width="26" height="40" rx="5" fill="#274635" />

                            <!-- Sparkle / Signal Rays above the "0" (Animated) -->
                            <g class="anim-sparkles">
                                <line x1="42" y1="-20" x2="42" y2="-9" stroke="#274635" stroke-width="4.5" stroke-linecap="round" />
                                <line x1="20" y1="-16" x2="25" y2="-7" stroke="#274635" stroke-width="4.5" stroke-linecap="round" />
                                <line x1="64" y1="-16" x2="59" y2="-7" stroke="#274635" stroke-width="4.5" stroke-linecap="round" />
                            </g>
                        </g>

                        <!-- Second "4" (Starts 560, Ends 637) -->
                        <path d="M605 165 L637 165 L637 187 L620 187 L620 207 L597 207 L597 187 L560 187 L560 165 L597 108 L620 108 L620 165 Z M597 165 L597 137 L577 165 Z" fill="#274635" />
                    </g>

                    <!-- Chip 4 (above 404 near dashed line) -->
                    <g transform="translate(415, 48)">
                        <g class="anim-chip-4">
                            <rect x="-10" y="-10" width="20" height="20" rx="3.5" fill="#274635" />
                            <rect x="-6.5" y="-6.5" width="13" height="13" rx="2" fill="#FFFFFF" />
                            <rect x="-3.5" y="-3.5" width="7" height="7" rx="1" fill="#274635" />
                        </g>
                    </g>

                    <!-- Chip 5 (right of second 4) -->
                    <g transform="translate(650, 190)">
                        <g class="anim-chip-5">
                            <rect x="-10" y="-10" width="20" height="20" rx="3.5" fill="#274635" />
                            <rect x="-6.5" y="-6.5" width="13" height="13" rx="2" fill="#FFFFFF" />
                            <rect x="-3.5" y="-3.5" width="7" height="7" rx="1" fill="#274635" />
                        </g>
                    </g>

                    <!-- Dashed Path Journey Arching to Map -->
                    <path d="M450 48 C520 20, 610 22, 670 48 C730 74, 770 110, 785 130" stroke="#9BB8A7" stroke-width="2.6" stroke-dasharray="6 6" stroke-linecap="round" />

                    <!-- Floating Tilted Modules on the Arch -->
                    <!-- Green Diamond -->
                    <g transform="translate(530, 28) rotate(15)">
                        <rect x="-9" y="-9" width="18" height="18" rx="3.5" fill="#274635" />
                        <rect x="-5.5" y="-5.5" width="11" height="11" rx="2" fill="#FFFFFF" />
                    </g>
                    <!-- Golden Yellow Diamond (Animated) -->
                    <g transform="translate(650, 38)">
                        <g class="anim-diamond-yellow">
                            <rect x="-10" y="-10" width="20" height="20" rx="4" fill="#F1A83B" />
                            <rect x="-6" y="-6" width="12" height="12" rx="2" fill="#FFFFFF" />
                        </g>
                    </g>

                    <!-- Destination Map & Checklist Area (Right) -->
                    <g id="destinationMap" transform="translate(720, 130)">
                        <!-- Folded Map Leaves -->
                        <polygon points="12,74 56,52 108,68 152,46 146,108 102,130 50,114 6,136" fill="#E4ECE7" stroke="#BACDC1" stroke-width="2" />
                        <polygon points="56,52 108,68 102,130 50,114" fill="#D3E2D8" stroke="#BACDC1" stroke-width="2" />

                        <!-- Dotted Search Target on Map -->
                        <ellipse cx="78" cy="95" rx="16" ry="8" fill="none" stroke="#789A84" stroke-width="2" stroke-dasharray="3 3" />

                        <!-- Location Pin Marker (Golden Yellow - Animated) -->
                        <g transform="translate(78, 95)">
                            <g class="anim-pin">
                                <path d="M0 -38 C-11 -38 -18 -28 -18 -17 C-18 -5 0 0 0 0 C0 0 18 -5 18 -17 C18 -28 11 -38 0 -38 Z" fill="#F1A83B" />
                                <circle cx="0" cy="-19" r="6" fill="#FFFFFF" />
                            </g>
                        </g>

                        <!-- Checklist Document Behind Pin -->
                        <g transform="translate(100, 8)">
                            <rect x="0" y="0" width="44" height="58" rx="4.5" fill="#FFFFFF" stroke="#C4D7CC" stroke-width="1.8" />
                            <!-- Checkbox 1 -->
                            <rect x="6" y="9" width="9" height="9" rx="2" fill="#EAF3ED" stroke="#4B8562" stroke-width="1.2" />
                            <path d="M8 13 L10 16 L14 11" stroke="#387750" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="18" y1="13" x2="38" y2="13" stroke="#9AB5A4" stroke-width="2" stroke-linecap="round" />

                            <!-- Checkbox 2 -->
                            <rect x="6" y="23" width="9" height="9" rx="2" fill="#EAF3ED" stroke="#4B8562" stroke-width="1.2" />
                            <path d="M8 27 L10 30 L14 25" stroke="#387750" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="18" y1="27" x2="38" y2="27" stroke="#9AB5A4" stroke-width="2" stroke-linecap="round" />

                            <!-- Line 3 -->
                            <line x1="7" y1="41" x2="38" y2="41" stroke="#BACDC1" stroke-width="2" stroke-linecap="round" />
                        </g>
                    </g>
                </svg>
            </div>

            <div class="error-content">
                <h1 class="error-title">Page not found</h1>
                <p class="error-subtitle">We couldn’t find that QR task page. The link may have moved or the task is no longer available.</p>
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
