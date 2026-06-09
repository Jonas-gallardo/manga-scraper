<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comic Scraper Pro — Descargador Masivo</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="alternate icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="favicon-180.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #080b12;
            color: #e2e8f0;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Animated gradient background ── */
        .bg-app {
            position: fixed;
            inset: 0;
            z-index: -1;
            background:
                radial-gradient(ellipse 80% 60% at 50% -20%, rgba(99,102,241,0.12) 0%, transparent 60%),
                radial-gradient(ellipse 40% 50% at 80% 80%, rgba(168,85,247,0.08) 0%, transparent 50%),
                radial-gradient(ellipse 30% 40% at 20% 60%, rgba(59,130,246,0.06) 0%, transparent 50%),
                #080b12;
        }

        /* ── Typography ── */
        .font-mono-custom {
            font-family: 'JetBrains Mono', 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
        }

        /* ── Glass Card ── */
        .glass {
            background: rgba(22, 27, 34, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(48, 54, 61, 0.5);
            border-radius: 1rem;
            transition: all 0.25s ease;
        }
        .glass:hover {
            border-color: rgba(99, 102, 241, 0.25);
        }
        .glass-strong {
            background: rgba(13, 17, 23, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 1rem;
        }
        .glass-input {
            background: rgba(13, 17, 23, 0.7);
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 0.75rem;
            color: #e2e8f0;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        .glass-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15), 0 0 20px rgba(99, 102, 241, 0.05);
        }
        .glass-input::placeholder { color: #4a5568; }

        /* ── Buttons ── */
        .btn-glow {
            position: relative;
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: white;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            overflow: hidden;
        }
        .btn-glow::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(135deg, #818cf8, #a78bfa);
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .btn-glow:hover:not(:disabled)::before { opacity: 1; }
        .btn-glow:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.35);
        }
        .btn-glow:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-glow > * { position: relative; z-index: 1; }

        .btn-danger {
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: white;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-danger:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.35);
        }
        .btn-danger:disabled { opacity: 0.4; cursor: not-allowed; }

        .btn-ghost {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            color: #94a3b8;
            background: rgba(30, 35, 45, 0.6);
            border: 1px solid rgba(48, 54, 61, 0.4);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-ghost:hover { background: rgba(40, 46, 56, 0.8); color: #e2e8f0; border-color: rgba(99, 102, 241, 0.3); }

        /* ── Nav Tabs ── */
        .nav-tab {
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            background: transparent;
            color: #64748b;
        }
        .nav-tab:hover { color: #e2e8f0; background: rgba(30, 35, 45, 0.5); }
        .nav-tab.active {
            color: white;
            background: linear-gradient(135deg, rgba(99,102,241,0.2), rgba(139,92,246,0.15));
            border: 1px solid rgba(99, 102, 241, 0.3);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.1);
        }

        .mode-tab {
            padding: 0.5rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            border: 1px solid transparent;
            background: rgba(13, 17, 23, 0.5);
            color: #64748b;
        }
        .mode-tab:hover { color: #e2e8f0; border-color: rgba(48, 54, 61, 0.5); }
        .mode-tab.active {
            color: white;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.25);
        }

        /* ── Tab Content ── */
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Log Terminal ── */
        #log {
            background: #0a0d14;
            color: #c9d1d9;
            font-family: 'JetBrains Mono', 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
            font-size: 0.775rem;
            line-height: 1.7;
            height: 20rem;
            overflow-y: auto;
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            scroll-behavior: smooth;
            white-space: pre-wrap;
            word-break: break-all;
            border: 1px solid rgba(48, 54, 61, 0.3);
        }
        #log .info    { color: #58a6ff; }
        #log .wait    { color: #d29922; }
        #log .warning { color: #d29922; }
        #log .error   { color: #f85149; }
        #log .success { color: #3fb950; }
        #log .progress { color: #79c0ff; }
        #log .complete { color: #3fb950; font-weight: 600; }
        #log .divider  { color: #30363d; }
        #log .done     { color: #f0883e; font-weight: 700; font-size: 1.05em; }

        #log::-webkit-scrollbar { width: 6px; }
        #log::-webkit-scrollbar-track { background: #0a0d14; }
        #log::-webkit-scrollbar-thumb { background: #21262d; border-radius: 3px; }
        #log::-webkit-scrollbar-thumb:hover { background: #30363d; }

        /* ── Progress Bar ── */
        .progress-track {
            height: 6px;
            background: rgba(13, 17, 23, 0.6);
            border-radius: 999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6, #a855f7, #6366f1);
            background-size: 200% 100%;
            transition: width 0.4s ease;
            width: 0%;
            animation: shimmer 2s linear infinite;
        }
        @keyframes shimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ── Spinner ── */
        .spinner {
            display: inline-block;
            width: 1.125rem;
            height: 1.125rem;
            border: 2px solid rgba(255,255,255,0.1);
            border-top-color: #818cf8;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Taxonomy Badges ── */
        .tax-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.1rem 0.45rem;
            border-radius: 9999px;
            font-size: 0.6rem;
            font-weight: 500;
            line-height: 1.3;
            white-space: nowrap;
            max-width: 7rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .tax-lang {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .tax-type {
            background: rgba(139, 92, 246, 0.15);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }
        .tax-tag {
            background: rgba(16, 185, 129, 0.12);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.15);
        }
        .tax-more {
            background: rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.15);
            font-size: 0.55rem;
        }
        .tax-author {
            background: rgba(244, 114, 182, 0.15);
            color: #f9a8d4;
            border: 1px solid rgba(244, 114, 182, 0.2);
        }
        .tax-character {
            background: rgba(251, 191, 36, 0.15);
            color: #fcd34d;
            border: 1px solid rgba(251, 191, 36, 0.2);
        }

        /* ── Comic Cards ── */
        .comic-card {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(48, 54, 61, 0.4);
            border-radius: 0.875rem;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        .comic-card:hover {
            transform: translateY(-4px);
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 12px 40px rgba(99, 102, 241, 0.12), 0 4px 12px rgba(0,0,0,0.3);
        }
        .comic-card .cover {
            width: 100%;
            aspect-ratio: 3/4;
            object-fit: cover;
            background: #0d1117;
        }
        .comic-card .cover-placeholder {
            width: 100%;
            aspect-ratio: 3/4;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(30, 35, 45, 0.8), rgba(40, 46, 56, 0.6));
            color: #4a5568;
            font-size: 3rem;
        }

        /* ── Stat Cards ── */
        .stat-card {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(48, 54, 61, 0.4);
            border-radius: 1rem;
            padding: 1.25rem;
            transition: all 0.2s ease;
        }
        .stat-card:hover {
            border-color: rgba(99, 102, 241, 0.25);
            transform: translateY(-2px);
        }
        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #818cf8, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Badges ── */
        .badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.625rem;
            border-radius: 999px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .badge-completo    { background: rgba(63,185,80,0.12); color: #3fb950; border: 1px solid rgba(63,185,80,0.2); }
        .badge-parcial     { background: rgba(210,153,34,0.12); color: #d29922; border: 1px solid rgba(210,153,34,0.2); }
        .badge-error       { background: rgba(248,81,73,0.12); color: #f85149; border: 1px solid rgba(248,81,73,0.2); }
        .badge-descargando { background: rgba(88,166,255,0.12); color: #58a6ff; border: 1px solid rgba(88,166,255,0.2); }

        /* ── Status dot ── */
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .status-dot.active   { background: #3fb950; box-shadow: 0 0 8px rgba(63,185,80,0.5); animation: pulse 1.5s infinite; }
        .status-dot.inactive { background: #4a5568; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* ── Viewer Modal ── */
        .viewer-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 1000;
            background: rgba(0,0,0,0.95);
            backdrop-filter: blur(8px);
        }
        .viewer-modal.open { display: flex; flex-direction: column; animation: fadeIn 0.2s ease; }
        .viewer-modal .viewer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            background: rgba(22, 27, 34, 0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(48, 54, 61, 0.5);
        }
        .viewer-modal .viewer-body {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .viewer-modal .viewer-body img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: transform 0.2s ease;
        }
        .viewer-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            padding: 1rem;
            cursor: pointer;
            color: white;
            font-size: 1.5rem;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            user-select: none;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .viewer-nav:hover { background: rgba(99,102,241,0.7); transform: translateY(-50%) scale(1.1); }
        .viewer-nav.prev { left: 1.5rem; }
        .viewer-nav.next { right: 1.5rem; }

        /* ── Select / Inputs ── */
        .select-filter {
            padding: 0.5rem 0.75rem;
            background: rgba(13, 17, 23, 0.7);
            border: 1px solid rgba(48, 54, 61, 0.6);
            border-radius: 0.75rem;
            color: #e2e8f0;
            font-size: 0.875rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        .select-filter:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
        }

        /* ── Settings Modal ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.open { display: flex; animation: fadeIn 0.25s ease; }
        .modal-content {
            background: rgba(18, 22, 30, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(48, 54, 61, 0.5);
            border-radius: 1.25rem;
            padding: 1.5rem;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .modal-content::-webkit-scrollbar { width: 4px; }
        .modal-content::-webkit-scrollbar-track { background: transparent; }
        .modal-content::-webkit-scrollbar-thumb { background: #30363d; border-radius: 2px; }

        /* ── Misc ── */
        .section-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,102,241,0.2), transparent);
            margin: 1rem 0;
        }
        .glow-text {
            background: linear-gradient(135deg, #818cf8, #c084fc, #f472b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Taxonomy Modal ── */
        .tax-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 1000;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .tax-modal-overlay.open { display: flex; animation: fadeIn 0.25s ease; }
        .tax-modal-content {
            background: rgba(18, 22, 30, 0.97);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(48, 54, 61, 0.5);
            border-radius: 1.25rem;
            padding: 1.5rem;
            width: 100%;
            max-width: 520px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
        }
        .tax-modal-content::-webkit-scrollbar { width: 4px; }
        .tax-modal-content::-webkit-scrollbar-track { background: transparent; }
        .tax-modal-content::-webkit-scrollbar-thumb { background: #30363d; border-radius: 2px; }
        .tax-section {
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(48, 54, 61, 0.3);
        }
        .tax-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .tax-section-title {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .tax-items {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .tax-item {
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 500;
            line-height: 1.4;
        }
        .tax-item-lang {
            background: rgba(59, 130, 246, 0.12);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.18);
        }
        .tax-item-universe {
            background: rgba(139, 92, 246, 0.12);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.18);
        }
        .tax-item-type {
            background: rgba(16, 185, 129, 0.12);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.15);
        }
        .tax-item-author {
            background: rgba(245, 158, 11, 0.12);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }
        .tax-item-tag {
            background: rgba(236, 72, 153, 0.1);
            color: #f9a8d4;
            border: 1px solid rgba(236, 72, 153, 0.12);
        }
        .tax-empty {
            color: #4a5568;
            font-size: 0.8rem;
            font-style: italic;
        }
        .btn-tax-view {
            padding: 0.3rem 0.65rem;
            border-radius: 0.5rem;
            font-size: 0.65rem;
            font-weight: 500;
            color: #a78bfa;
            background: rgba(139, 92, 246, 0.1);
            border: 1px solid rgba(139, 92, 246, 0.2);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            line-height: 1.3;
        }
        .btn-tax-view:hover {
            background: rgba(139, 92, 246, 0.2);
            border-color: rgba(139, 92, 246, 0.4);
            color: #c4b5fd;
            transform: translateY(-1px);
        }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .glass, .glass-strong { border-radius: 0.75rem; }
            .stat-card .stat-value { font-size: 1.35rem; }
            #log { height: 16rem; font-size: 0.7rem; }
            .nav-tab { padding: 0.5rem 0.875rem; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

    <!-- ── Background ── -->
    <div class="bg-app"></div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6">

        <!-- ═══ HEADER ═══ -->
        <header class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-2">
                <span class="text-3xl">🦸</span>
                <h1 class="text-3xl sm:text-4xl font-extrabold glow-text tracking-tight">
                    Comic Scraper Pro
                </h1>
            </div>
            <p class="text-gray-500 text-sm max-w-md mx-auto">
                Descargador masivo de cómics con evasión anti-bloqueo
            </p>
        </header>

        <!-- ═══ NAV TABS ═══ -->
        <div class="glass-strong p-1.5 mb-6 flex flex-wrap gap-1" id="mainTabs" style="border-radius:0.875rem">
            <button class="nav-tab active" data-tab="download">
                <span>⬇️</span> Descargar
            </button>
            <button class="nav-tab" data-tab="gallery">
                <span>🖼️</span> Galería
                <span id="galleryCount" class="text-xs bg-indigo-900/50 text-indigo-300 px-1.5 py-0.5 rounded-full hidden ml-1"></span>
            </button>
            <button class="nav-tab" data-tab="dashboard">
                <span>📊</span> Dashboard
            </button>
            <button class="nav-tab" data-tab="dictionary">
                <span>📖</span> Diccionario
            </button>
            <button class="nav-tab" data-tab="wpublish">
                <span>🚀</span> WP Publisher
            </button>
            <button id="btnOpenSettings" class="nav-tab" style="margin-left:auto">
                <span>⚙️</span> Configuración
            </button>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB 1: DESCARGA                                    -->
        <!-- ════════════════════════════════════════════════════ -->
        <div class="tab-content active" id="tab-download">

            <div class="glass p-5 sm:p-6 mb-6">
                <!-- Mode tabs -->
                <div class="flex gap-2 mb-5" id="modeTabs">
                    <button class="mode-tab active" data-mode="single">
                        📖 Cómic Individual
                    </button>
                    <button class="mode-tab" data-mode="batch">
                        🌌 Universo / Batch
                    </button>
                </div>

                <form id="scrapeForm" class="space-y-4">
                    <input type="hidden" name="action" id="actionInput" value="single">

                    <!-- URL -->
                    <div>
                        <label for="urlInput" class="block text-sm font-medium text-gray-400 mb-1.5">
                            URL del cómic o búsqueda
                        </label>
                        <input type="url" id="urlInput" name="url" required
                            placeholder="https://es.3hentai.net/d/627574"
                            class="glass-input w-full px-4 py-2.5">
                    </div>

                    <!-- Batch controls -->
                    <div id="batchControls" class="hidden">
                        <div class="glass-strong p-4 grid grid-cols-1 sm:grid-cols-2 gap-4" style="border-radius:0.75rem">
                            <div>
                                <label for="startPage" class="block text-xs text-gray-500 mb-1">Página inicial del listado</label>
                                <input type="number" id="startPage" name="start_page"
                                    value="1" min="1" max="9999"
                                    class="glass-input w-full px-3 py-2">
                                <p class="text-xs text-gray-600 mt-1">💡 Ej: si ya descargaste páginas 1-3, empezar desde <code class="text-indigo-400">4</code></p>
                            </div>
                            <div>
                                <label for="maxComics" class="block text-xs text-gray-500 mb-1">Máximo de cómics a descargar</label>
                                <input type="number" id="maxComics" name="max_comics"
                                    value="50" min="1" max="9999"
                                    class="glass-input w-full px-3 py-2">
                                <p class="text-xs text-gray-600 mt-1">💡 Límite total de cómics para esta sesión</p>
                            </div>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="flex items-center gap-3 flex-wrap">
                        <button type="submit" id="btnStart" class="btn-glow">
                            🚀 Iniciar Descarga
                        </button>
                        <button type="button" id="btnStop" class="btn-danger" disabled>
                            ⏹ Detener
                        </button>
                        <button type="button" id="btnClear" class="btn-ghost">
                            🗑 Limpiar Log
                        </button>
                    </div>

                    <p id="modeHint" class="text-xs text-gray-500">
                        💡 Ingresa la URL completa del cómic individual (<code class="text-indigo-400">/d/ID</code>).
                        El sistema verificará si ya fue descargado antes de comenzar.
                    </p>
                </form>
            </div>

            <!-- Progress Bar -->
            <div id="progressContainer" class="glass p-5 mb-6 hidden">
                <div class="flex justify-between text-sm mb-2">
                    <span id="progressLabel" class="text-gray-400 font-medium">Progreso</span>
                    <span id="progressPercent" class="text-indigo-400 font-mono font-semibold">0%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <p id="progressDetail" class="text-xs text-gray-600 mt-2"></p>
            </div>

            <!-- Log -->
            <div class="glass p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <span>📋</span> Consola de Progreso
                    </h2>
                    <span id="statusBadge" class="text-xs px-2.5 py-0.5 rounded-full bg-gray-800/50 text-gray-500 flex items-center gap-1.5">
                        <span class="status-dot inactive"></span>
                        Inactivo
                    </span>
                </div>
                <div id="log"></div>
            </div>

            <!-- ════════════════════════════════════════════════ -->
            <!-- HISTORIAL DE BATCH                              -->
            <!-- ════════════════════════════════════════════════ -->
            <div class="glass p-5 mt-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-widest flex items-center gap-2">
                        <span>📜</span> Historial de Ejecuciones Batch
                    </h2>
                    <button id="batchHistoryRefresh" class="btn-ghost text-xs px-2 py-1" title="Recargar historial">
                        🔄
                    </button>
                </div>

                <!-- Search -->
                <div class="mb-3">
                    <input type="text" id="batchHistorySearch" placeholder="Buscar por URL o universo..."
                        class="glass-input w-full px-3 py-2 text-sm">
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500 text-xs uppercase tracking-wider">
                                <th class="text-left py-2 pr-2">URL / Universo</th>
                                <th class="text-center py-2 px-2">Páginas</th>
                                <th class="text-center py-2 px-2">↓ OK</th>
                                <th class="text-center py-2 px-2">⏭ Omit</th>
                                <th class="text-center py-2 px-2">❌ Err</th>
                                <th class="text-center py-2 px-2">Estado</th>
                                <th class="text-center py-2 px-2">Fecha</th>
                                <th class="text-center py-2 px-2">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="batchHistoryBody">
                            <tr>
                                <td colspan="8" class="text-center py-6 text-gray-600">
                                    <span class="spinner"></span>
                                    <span class="ml-2">Cargando historial...</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div id="batchHistoryPagination" class="flex items-center justify-center gap-3 mt-3 hidden">
                    <button id="bhPrevPage" class="btn-ghost text-xs px-2 py-1" disabled>◀ Anterior</button>
                    <span id="bhPageInfo" class="text-xs text-gray-500"></span>
                    <button id="bhNextPage" class="btn-ghost text-xs px-2 py-1" disabled>Siguiente ▶</button>
                </div>

                <div id="batchHistoryEmpty" class="text-center py-6 hidden">
                    <p class="text-gray-500 text-sm">No hay historial de ejecuciones batch.</p>
                    <p class="text-gray-600 text-xs mt-1">Usa el modo "Batch" para descargar y aparecerá aquí.</p>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB 2: GALERÍA                                     -->
        <!-- ════════════════════════════════════════════════════ -->
        <div class="tab-content" id="tab-gallery">

            <!-- Filters -->
            <div class="glass p-5 mb-6">
                <div class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 mb-1">Buscar</label>
                        <input type="text" id="gallerySearch" placeholder="Título, ID, tags..."
                            class="glass-input w-full px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Universo</label>
                        <select id="galleryUniverso" class="select-filter">
                            <option value="">Todos</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Estado</label>
                        <select id="galleryEstado" class="select-filter">
                            <option value="">Todos</option>
                            <option value="completo">Completo</option>
                            <option value="parcial">Parcial</option>
                            <option value="error">Error</option>
                            <option value="descargando">Descargando</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Fecha desde</label>
                        <input type="date" id="galleryFechaDesde" class="select-filter" style="color-scheme:dark">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Fecha hasta</label>
                        <input type="date" id="galleryFechaHasta" class="select-filter" style="color-scheme:dark">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Ordenar</label>
                        <select id="gallerySort" class="select-filter">
                            <option value="fecha_descarga">Fecha ▼</option>
                            <option value="titulo">Título ▲</option>
                            <option value="id_fuente">ID ▲</option>
                            <option value="universo">Universo ▲</option>
                            <option value="total_paginas">Páginas ▼</option>
                        </select>
                    </div>
                    <button id="galleryRefresh" class="btn-ghost text-sm" title="Refrescar galería">
                        🔄
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <button id="btnClearDateFilters" class="btn-ghost text-xs px-2 py-1">
                        ❌ Limpiar filtros de fecha
                    </button>
                    <span id="galleryDeletedCount" class="text-xs text-gray-500 ml-auto hidden"></span>
                </div>
            </div>

            <!-- Grid -->
            <div id="galleryGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-6"></div>

            <!-- Loading -->
            <div id="galleryLoading" class="text-center py-8 hidden">
                <span class="spinner"></span>
                <p class="text-gray-500 text-sm mt-3">Cargando galería...</p>
            </div>

            <!-- Empty -->
            <div id="galleryEmpty" class="text-center py-12 hidden">
                <div class="text-5xl mb-4 opacity-40">📭</div>
                <p class="text-gray-400 font-medium">No hay cómics descargados aún.</p>
                <p class="text-gray-600 text-sm mt-1">Usa la pestaña "Descargar" para comenzar.</p>
            </div>

            <!-- Pagination -->
            <div id="galleryPagination" class="flex items-center justify-center gap-3 mt-4 hidden">
                <button id="prevPage" class="btn-ghost text-sm" disabled>◀ Anterior</button>
                <span id="pageInfo" class="text-sm text-gray-500"></span>
                <button id="nextPage" class="btn-ghost text-sm" disabled>Siguiente ▶</button>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB 3: DASHBOARD                                    -->
        <!-- ════════════════════════════════════════════════════ -->
        <div class="tab-content" id="tab-dashboard">
            <div id="dashboardContent">
                <div class="text-center py-12">
                    <span class="spinner"></span>
                    <p class="text-gray-500 text-sm mt-3">Cargando estadísticas...</p>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB 4: DICCIONARIO                                  -->
        <!-- ════════════════════════════════════════════════════ -->
        <div class="tab-content" id="tab-dictionary">
            <iframe src="dictionary.php" class="w-full border-0" style="min-height:80vh;border-radius:0.75rem"></iframe>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB 5: WP PUBLISHER                                -->
        <!-- ════════════════════════════════════════════════════ -->
        <div class="tab-content" id="tab-wpublish">
            <iframe src="publish_to_wp.php" class="w-full border-0" style="min-height:85vh;border-radius:0.75rem"></iframe>
        </div>

        <!-- ═══ FOOTER ═══ -->
        <div class="text-center mt-10 pt-6 border-t border-gray-800/50">
            <p class="text-xs text-gray-600">
                Ejecutado en entorno local &bull; PHP + MySQL + cURL &bull;
                Pausas aleatorias evitan el Rate Limiting &bull;
                <a href="#" onclick="location.reload()" class="text-indigo-400 hover:text-indigo-300 transition-colors">Recargar</a>
            </p>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- VISOR MODAL                                        -->
    <!-- ════════════════════════════════════════════════════ -->
    <div id="viewerModal" class="viewer-modal">
        <div class="viewer-header">
            <div class="min-w-0">
                <h3 id="viewerTitle" class="text-sm font-medium text-gray-200 truncate"></h3>
                <p id="viewerPageInfo" class="text-xs text-gray-500"></p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button id="viewerZoomIn" class="glass-input px-2 py-1 text-sm text-gray-400 hover:text-white" title="Acercar">🔍+</button>
                <button id="viewerZoomOut" class="glass-input px-2 py-1 text-sm text-gray-400 hover:text-white" title="Alejar">🔍−</button>
                <button id="viewerFitWidth" class="glass-input px-2 py-1 text-sm text-gray-400 hover:text-white" title="Ajustar al ancho">⬌</button>
                <button id="viewerClose" class="text-gray-400 hover:text-white text-2xl leading-none ml-2">&times;</button>
            </div>
        </div>
        <div class="viewer-body" id="viewerBody">
            <div class="viewer-nav prev" id="viewerPrev">◀</div>
            <img id="viewerImage" src="" alt="Cargando...">
            <div class="viewer-nav next" id="viewerNext">▶</div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- SETTINGS MODAL                                     -->
    <!-- ════════════════════════════════════════════════════ -->
    <div id="settingsModal" class="modal-overlay">
        <div class="modal-content">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-bold text-gray-100 flex items-center gap-2">
                    <span>⚙️</span> Configuración del Sistema
                </h2>
                <button id="settingsClose" class="text-gray-500 hover:text-white text-2xl leading-none transition-colors">&times;</button>
            </div>

            <form id="settingsForm" class="space-y-5">

                <!-- DB -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">🗄️</span>
                        <h3 class="text-sm font-semibold text-indigo-400">Base de Datos MySQL</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Host</label>
                            <input type="text" id="cfg_db_host" class="glass-input w-full px-3 py-2 text-sm" value="localhost">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Base de datos</label>
                            <input type="text" id="cfg_db_name" class="glass-input w-full px-3 py-2 text-sm" value="comics_db">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Usuario</label>
                            <input type="text" id="cfg_db_user" class="glass-input w-full px-3 py-2 text-sm" value="root">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Contraseña</label>
                            <input type="password" id="cfg_db_pass" class="glass-input w-full px-3 py-2 text-sm" placeholder="(vacía en XAMPP)">
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- Site -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">🌐</span>
                        <h3 class="text-sm font-semibold text-indigo-400">Sitio Web de Origen</h3>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">URL Base</label>
                        <input type="url" id="cfg_site_url" class="glass-input w-full px-3 py-2 text-sm" value="https://sitio.com" placeholder="https://sitio.com">
                        <p class="text-xs text-gray-600 mt-1">⚠️ NO son credenciales de usuario del sitio, solo la dirección web</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Ruta vista individual</label>
                            <input type="text" id="cfg_view_path" class="glass-input w-full px-3 py-2 text-sm" value="/view" placeholder="/view">
                            <p class="text-xs text-gray-600 mt-1">Ej: <code class="text-indigo-400">/d</code> para 3hentai</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Ruta búsqueda/batch</label>
                            <input type="text" id="cfg_batch_path" class="glass-input w-full px-3 py-2 text-sm" value="/parody" placeholder="/parody">
                            <p class="text-xs text-gray-600 mt-1">Ej: <code class="text-indigo-400">/search</code> para 3hentai</p>
                        </div>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- Download Path -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">📁</span>
                        <h3 class="text-sm font-semibold text-indigo-400">Directorio de Descargas</h3>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Ruta de almacenamiento</label>
                        <input type="text" id="cfg_download_path" class="glass-input w-full px-3 py-2 text-sm font-mono-custom" placeholder="/opt/lampp/htdocs/scrap/descargas">
                        <p class="text-xs text-gray-600 mt-1">Ruta absoluta o relativa. Si se deja vacío, usa el valor por defecto.</p>
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- Anti-Ban -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">🛡️</span>
                        <h3 class="text-sm font-semibold text-indigo-400">Anti-Ban</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Delay páginas (s) min–max</label>
                            <div class="flex gap-2">
                                <input type="number" id="cfg_delay_p_min" class="glass-input w-full px-3 py-2 text-sm" value="1.5" step="0.1">
                                <input type="number" id="cfg_delay_p_max" class="glass-input w-full px-3 py-2 text-sm" value="3.5" step="0.1">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Delay cómics (s) min–max</label>
                            <div class="flex gap-2">
                                <input type="number" id="cfg_delay_c_min" class="glass-input w-full px-3 py-2 text-sm" value="5">
                                <input type="number" id="cfg_delay_c_max" class="glass-input w-full px-3 py-2 text-sm" value="10">
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="block text-xs text-gray-500 mb-1">Reintentos máximos</label>
                        <input type="number" id="cfg_retries" class="glass-input w-full px-3 py-2 text-sm" value="3" min="0" max="10">
                    </div>
                </div>

                <div class="section-divider"></div>

                <!-- WordPress -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">🚀</span>
                        <h3 class="text-sm font-semibold text-indigo-400">WordPress — Publicación Automática</h3>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">URL del Sitio WordPress</label>
                        <input type="url" id="cfg_wp_url" class="glass-input w-full px-3 py-2 text-sm" value="http://localhost:10003" placeholder="http://localhost:10003">
                        <p class="text-xs text-gray-600 mt-1">URL base de tu instalación WordPress (con puerto si aplica)</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Usuario</label>
                            <input type="text" id="cfg_wp_user" class="glass-input w-full px-3 py-2 text-sm" value="admin">
                            <p class="text-xs text-gray-600 mt-1">Usuario con Application Password generado</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Application Password</label>
                            <input type="password" id="cfg_wp_pass" class="glass-input w-full px-3 py-2 text-sm" placeholder="(requerido)">
                            <p class="text-xs text-gray-600 mt-1">Generar en: Usuarios → Perfil → Application Passwords</p>
                        </div>
                    </div>
                </div>

                <div id="settingsMsg" class="hidden text-sm p-3 rounded-lg"></div>

                <button type="submit" id="btnSaveSettings" class="btn-glow w-full text-sm">
                    💾 Guardar Configuración
                </button>
            </form>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- TAXONOMY MODAL                                     -->
    <!-- ════════════════════════════════════════════════════ -->
    <div id="taxModal" class="tax-modal-overlay">
        <div class="tax-modal-content">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-gray-100 flex items-center gap-2" id="taxModalTitle">
                    <span>🏷️</span> Taxonomías
                </h2>
                <button id="taxModalClose" class="text-gray-500 hover:text-white text-2xl leading-none transition-colors">&times;</button>
            </div>
            <div id="taxModalBody">
                <!-- populated dynamically -->
            </div>
        </div>
    </div>

    <script>
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  CONFIG FROM PHP (paths configurables)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    const SITE_VIEW_PATH  = '<?php echo defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view'; ?>';
    const SITE_BATCH_PATH = '<?php echo defined('SITE_BATCH_PATH') ? SITE_BATCH_PATH : '/parody'; ?>';

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  JAVASCRIPT — FRONTEND LÓGICO
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    (function() {
        'use strict';

        // ── Referencias DOM ──
        const form              = document.getElementById('scrapeForm');
        const urlInput          = document.getElementById('urlInput');
        const actionInput       = document.getElementById('actionInput');
        const btnStart          = document.getElementById('btnStart');
        const btnStop           = document.getElementById('btnStop');
        const btnClear          = document.getElementById('btnClear');
        const logEl             = document.getElementById('log');
        const modeTabs          = document.querySelectorAll('#modeTabs .mode-tab');
        const modeHint          = document.getElementById('modeHint');
        const batchControls     = document.getElementById('batchControls');
        const progressContainer = document.getElementById('progressContainer');
        const progressFill      = document.getElementById('progressFill');
        const progressLabel     = document.getElementById('progressLabel');
        const progressPercent   = document.getElementById('progressPercent');
        const progressDetail    = document.getElementById('progressDetail');
        const statusBadge       = document.getElementById('statusBadge');
        const mainTabs          = document.querySelectorAll('#mainTabs .nav-tab');

        // Galería
        const galleryGrid       = document.getElementById('galleryGrid');
        const galleryLoading    = document.getElementById('galleryLoading');
        const galleryEmpty      = document.getElementById('galleryEmpty');
        const gallerySearch     = document.getElementById('gallerySearch');
        const galleryUniverso   = document.getElementById('galleryUniverso');
        const galleryEstado     = document.getElementById('galleryEstado');
        const galleryFechaDesde = document.getElementById('galleryFechaDesde');
        const galleryFechaHasta = document.getElementById('galleryFechaHasta');
        const gallerySort       = document.getElementById('gallerySort');
        const galleryRefresh    = document.getElementById('galleryRefresh');
        const galleryPagination = document.getElementById('galleryPagination');
        const prevPageBtn       = document.getElementById('prevPage');
        const nextPageBtn       = document.getElementById('nextPage');
        const pageInfo          = document.getElementById('pageInfo');
        const galleryCount      = document.getElementById('galleryCount');
        const btnClearDateFilters = document.getElementById('btnClearDateFilters');

        // Historial Batch
        const batchHistoryBody      = document.getElementById('batchHistoryBody');
        const batchHistorySearch    = document.getElementById('batchHistorySearch');
        const batchHistoryRefresh   = document.getElementById('batchHistoryRefresh');
        const batchHistoryPagination = document.getElementById('batchHistoryPagination');
        const batchHistoryEmpty     = document.getElementById('batchHistoryEmpty');
        const bhPrevPage            = document.getElementById('bhPrevPage');
        const bhNextPage            = document.getElementById('bhNextPage');
        const bhPageInfo            = document.getElementById('bhPageInfo');

        // Visor
        const viewerModal       = document.getElementById('viewerModal');
        const viewerImage       = document.getElementById('viewerImage');
        const viewerTitle       = document.getElementById('viewerTitle');
        const viewerPageInfo    = document.getElementById('viewerPageInfo');
        const viewerPrev        = document.getElementById('viewerPrev');
        const viewerNext        = document.getElementById('viewerNext');
        const viewerClose       = document.getElementById('viewerClose');
        const viewerZoomIn      = document.getElementById('viewerZoomIn');
        const viewerZoomOut     = document.getElementById('viewerZoomOut');
        const viewerFitWidth    = document.getElementById('viewerFitWidth');
        const viewerBody        = document.getElementById('viewerBody');

        let abortController = null;
        let isRunning       = false;

        // Estado del visor
        let viewerPages     = [];
        let viewerCurrent   = 0;
        let viewerZoom      = 1;
        let viewerFit       = false;

        // Estado de galería
        let galleryPage     = 1;
        let galleryTotalPages = 1;
        let galleryData     = [];

        // Estado del historial batch
        let bhPage        = 1;
        let bhTotalPages  = 1;
        let bhSearchTimer = null;

        // ── Hints según modo (usando paths configurables) ──
        const HINTS = {
            single: `💡 Ingresa la URL completa del cómic individual (<code class="text-indigo-400">${SITE_VIEW_PATH}/ID</code>). El sistema verificará duplicados en BD y disco, y podrá reanudar descargas incompletas.`,
            batch:  `💡 Ingresa la URL de búsqueda (<code class="text-indigo-400">${SITE_BATCH_PATH}?q=...</code>). Usa los controles inferiores para elegir desde qué página del listado empezar y cuántos cómics descargar como máximo. El sistema paginará automáticamente.`
        };

        // ── Cambio de tabs principales ──
        mainTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                mainTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const target = tab.dataset.tab;
                document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                document.getElementById('tab-' + target).classList.add('active');

                if (target === 'gallery') cargarGaleria();
                if (target === 'dashboard') cargarDashboard();
            });
        });

        // ── Cambio de modo (single/batch) ──
        modeTabs.forEach(btn => {
            btn.addEventListener('click', () => {
                if (isRunning) return;
                modeTabs.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const mode = btn.dataset.mode;
                actionInput.value = mode;
                modeHint.innerHTML = HINTS[mode];
                urlInput.placeholder = mode === 'single'
                    ? 'https://es.3hentai.net/d/627574'
                    : 'https://es.3hentai.net/search?q=demon+slayer+spanish';
                batchControls.classList.toggle('hidden', mode !== 'batch');
            });
        });

        // ── Log ──
        function appendToLog(data) {
            const type = data.type || 'info';
            const msg  = data.message || '';

            if (data.current !== undefined && data.total !== undefined) {
                updateProgress(data.current, data.total, msg);
            }

            const line = document.createElement('div');
            line.className = type;
            const safeMsg = msg.replace(/&/g, '&').replace(/</g, '<').replace(/>/g, '>');
            line.textContent = safeMsg;
            logEl.appendChild(line);
            logEl.scrollTop = logEl.scrollHeight;
        }

        function updateProgress(current, total, msg) {
            progressContainer.classList.remove('hidden');
            const pct = total > 0 ? Math.round((current / total) * 100) : 0;
            progressFill.style.width = pct + '%';
            progressPercent.textContent = pct + '%';
            progressLabel.textContent = `Página ${current} de ${total}`;
            progressDetail.textContent = msg || '';
        }

        function resetProgress() {
            progressContainer.classList.add('hidden');
            progressFill.style.width = '0%';
            progressPercent.textContent = '0%';
            progressLabel.textContent = 'Progreso';
            progressDetail.textContent = '';
        }

        function setRunning(running) {
            isRunning = running;
            btnStart.disabled = running;
            btnStop.disabled  = !running;
            urlInput.disabled = running;
            modeTabs.forEach(b => b.style.pointerEvents = running ? 'none' : 'auto');
            statusBadge.innerHTML = running
                ? '<span class="status-dot active"></span> En Progreso'
                : '<span class="status-dot inactive"></span> Inactivo';
        }

        // ── Iniciar scraping ──
        async function startScraping(action, url, startPage, maxComics) {
            setRunning(true);
            resetProgress();

            appendToLog({
                type: 'info',
                message: `🚀 INICIANDO — Modo: ${action.toUpperCase()} — URL: ${url}`
            });
            if (action === 'batch') {
                appendToLog({
                    type: 'info',
                    message: `📄 Página inicial: ${startPage}  |  Máx. cómics: ${maxComics}`
                });
            }
            appendToLog({
                type: 'divider',
                message: '──────────────────────────────────────────────'
            });

            const formData = new FormData();
            formData.append('action', action);
            formData.append('url', url);
            if (action === 'batch') {
                formData.append('start_page', startPage);
                formData.append('max_comics', maxComics);
            }

            abortController = new AbortController();

            try {
                const response = await fetch('scraper.php', {
                    method: 'POST',
                    body:   formData,
                    signal: abortController.signal,
                });

                if (!response.ok) {
                    appendToLog({
                        type: 'error',
                        message: `Error HTTP ${response.status} — ${response.statusText}`
                    });
                    setRunning(false);
                    return;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        const trimmed = line.trim();
                        if (!trimmed) continue;
                        try {
                            const data = JSON.parse(trimmed);
                            appendToLog(data);
                        } catch (e) {
                            appendToLog({ type: 'info', message: trimmed });
                        }
                    }
                }

                if (buffer.trim()) {
                    try {
                        const data = JSON.parse(buffer.trim());
                        appendToLog(data);
                    } catch (e) {
                        appendToLog({ type: 'info', message: buffer.trim() });
                    }
                }

            } catch (err) {
                if (err.name === 'AbortError') {
                    appendToLog({ type: 'warning', message: '⏹  PROCESO DETENIDO por el usuario.' });
                } else {
                    appendToLog({ type: 'error', message: `💥 Error de conexión: ${err.message}` });
                }
            } finally {
                setRunning(false);
                abortController = null;
                appendToLog({ type: 'divider', message: '──────────────────────────────────────────────' });
                cargarGaleria();
                cargarDashboard();
            }
        }

        // ── Submit ──
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (isRunning) return;

            const action = actionInput.value;
            const url    = urlInput.value.trim();

            if (!url) {
                appendToLog({ type: 'error', message: '⚠️  Debes ingresar una URL.' });
                return;
            }

            const viewPathEscaped  = SITE_VIEW_PATH.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const batchPathEscaped = SITE_BATCH_PATH.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            if (action === 'single') {
                const singleRegex = new RegExp(viewPathEscaped + '/\\d+');
                if (!singleRegex.test(url)) {
                    appendToLog({ type: 'error', message: `⚠️  La URL no contiene ${SITE_VIEW_PATH}/ID. Verifica el formato.` });
                    return;
                }
            }
            if (action === 'batch') {
                // Aceptar URL con el path batch configurado (ej: /parody/NOMBRE)
                const batchRegex = new RegExp(batchPathEscaped);
                // Aceptar también URL con query de búsqueda (ej: /search?q=...)
                const searchQueryRegex = /[?&]q=[^&#]+/;
                if (!batchRegex.test(url) && !searchQueryRegex.test(url)) {
                    appendToLog({ type: 'error', message: `⚠️  La URL debe contener ${SITE_BATCH_PATH} (o ?q=... para búsqueda). Verifica el formato.` });
                    return;
                }
            }

            const startPage = parseInt(document.getElementById('startPage').value) || 1;
            const maxComics = parseInt(document.getElementById('maxComics').value) || 50;

            startScraping(action, url, startPage, maxComics);
        });

        // ── Stop ──
        btnStop.addEventListener('click', async () => {
            // Enviar señal de detención al servidor para limpiar descargas incompletas
            try {
                await fetch('stop_scraper.php', { method: 'POST' });
            } catch (e) {
                console.warn('No se pudo enviar la señal de stop al servidor', e);
            }
            // Abortar la petición HTTP (también dispara connection_aborted en PHP)
            if (abortController) {
                abortController.abort();
                btnStop.disabled = true;
            }
        });

        // ── Clear log ──
        btnClear.addEventListener('click', () => {
            logEl.innerHTML = '';
            resetProgress();
        });

        // ── Log inicial ──
        appendToLog({ type: 'info', message: '🔧 Sistema listo. Selecciona un modo, ingresa la URL y presiona "Iniciar Descarga".' });
        appendToLog({ type: 'info', message: '🛡️  Anti-Ban activo: User-Agent real, pausas aleatorias, reintentos automáticos.' });
        appendToLog({ type: 'info', message: '🆕  Nuevo: paginación batch, reanudación, detección mejorada de duplicados, galería y dashboard.' });


        // ════════════════════════════════════════════════════════════
        //  HISTORIAL BATCH
        // ════════════════════════════════════════════════════════════

        async function cargarHistorialBatch() {
            const params = new URLSearchParams({
                page: bhPage,
                per_page: 15,
                search: batchHistorySearch.value
            });

            batchHistoryBody.innerHTML = '<tr><td colspan="8" class="text-center py-6 text-gray-600"><span class="spinner"></span> <span class="ml-2">Cargando historial...</span></td></tr>';
            batchHistoryPagination.classList.add('hidden');
            batchHistoryEmpty.classList.add('hidden');

            try {
                const res = await fetch('batch_history.php?' + params.toString());
                const data = await res.json();

                if (!data.success || !data.registros || data.registros.length === 0) {
                    batchHistoryBody.innerHTML = '';
                    batchHistoryEmpty.classList.remove('hidden');
                    return;
                }

                let html = '';
                data.registros.forEach(r => {
                    // Truncar URL larga para mostrar
                    const urlDisplay = r.url_base.length > 60 ? r.url_base.substring(0, 57) + '...' : r.url_base;
                    const universoDisplay = r.universo || '-';
                    const estadoBadge = r.completado
                        ? '<span class="badge badge-completo">✅ Completado</span>'
                        : '<span class="badge badge-parcial">⏳ Parcial</span>';

                    html += `
                        <tr class="border-b border-gray-800/50 hover:bg-gray-800/30 transition-colors">
                            <td class="py-2 pr-2">
                                <div class="text-gray-200 text-xs font-medium truncate max-w-[200px]" title="${r.url_base}">${urlDisplay}</div>
                                <div class="text-gray-500 text-xs">${universoDisplay}</div>
                            </td>
                            <td class="text-center py-2 px-2 text-gray-400 text-xs">${r.pagina_inicial}–${r.ultima_pagina}</td>
                            <td class="text-center py-2 px-2 text-green-400 text-xs font-medium">${r.comics_descargados}</td>
                            <td class="text-center py-2 px-2 text-yellow-400 text-xs">${r.comics_omitidos}</td>
                            <td class="text-center py-2 px-2 text-red-400 text-xs">${r.comics_errores}</td>
                            <td class="text-center py-2 px-2">${estadoBadge}</td>
                            <td class="text-center py-2 px-2 text-gray-500 text-xs">${r.fecha_formateada}</td>
                            <td class="text-center py-2 px-2">
                                <button class="btn-ghost text-xs px-2 py-1 re-run-btn" data-url="${r.url_base}" data-page="${r.resume_page}" title="Re-ejecutar desde página ${r.resume_page}">
                                    ▶ Reanudar
                                </button>
                            </td>
                        </tr>
                    `;
                });

                batchHistoryBody.innerHTML = html;

                // Event listeners para botones "Reanudar"
                batchHistoryBody.querySelectorAll('.re-run-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const url = btn.dataset.url;
                        const page = btn.dataset.page;
                        // Cambiar a modo batch
                        document.querySelector('.mode-tab[data-mode="batch"]').click();
                        // Rellenar formulario
                        urlInput.value = url;
                        document.getElementById('startPage').value = page;
                        // Scroll al formulario
                        document.getElementById('scrapeForm').scrollIntoView({ behavior: 'smooth' });
                    });
                });

                // Paginación
                bhTotalPages = data.total_pages || 1;
                if (bhTotalPages > 1) {
                    batchHistoryPagination.classList.remove('hidden');
                    bhPageInfo.textContent = `Página ${data.page} de ${bhTotalPages} (${data.total} registros)`;
                    bhPrevPage.disabled = data.page <= 1;
                    bhNextPage.disabled = data.page >= bhTotalPages;
                }

            } catch (err) {
                batchHistoryBody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-red-400 text-sm">Error al cargar historial: ${err.message}</td></tr>`;
            }
        }

        // ── Event listeners del historial batch ──
        batchHistorySearch.addEventListener('input', () => {
            clearTimeout(bhSearchTimer);
            bhSearchTimer = setTimeout(() => { bhPage = 1; cargarHistorialBatch(); }, 400);
        });

        batchHistoryRefresh.addEventListener('click', () => cargarHistorialBatch());

        bhPrevPage.addEventListener('click', () => {
            if (bhPage > 1) { bhPage--; cargarHistorialBatch(); }
        });

        bhNextPage.addEventListener('click', () => {
            if (bhPage < bhTotalPages) { bhPage++; cargarHistorialBatch(); }
        });

        // Cargar historial al inicio
        cargarHistorialBatch();


        // ════════════════════════════════════════════════════════════
        //  GALERÍA
        // ════════════════════════════════════════════════════════════

        let searchTimeout = null;

        gallerySearch.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => { galleryPage = 1; cargarGaleria(); }, 400);
        });

        galleryUniverso.addEventListener('change', () => { galleryPage = 1; cargarGaleria(); });
        galleryEstado.addEventListener('change', () => { galleryPage = 1; cargarGaleria(); });
        galleryFechaDesde.addEventListener('change', () => { galleryPage = 1; cargarGaleria(); });
        galleryFechaHasta.addEventListener('change', () => { galleryPage = 1; cargarGaleria(); });
        gallerySort.addEventListener('change', () => { galleryPage = 1; cargarGaleria(); });
        galleryRefresh.addEventListener('click', () => cargarGaleria());

        // ── Limpiar filtros de fecha ──
        btnClearDateFilters.addEventListener('click', () => {
            galleryFechaDesde.value = '';
            galleryFechaHasta.value = '';
            galleryPage = 1;
            cargarGaleria();
        });

        prevPageBtn.addEventListener('click', () => {
            if (galleryPage > 1) { galleryPage--; cargarGaleria(); }
        });
        nextPageBtn.addEventListener('click', () => {
            if (galleryPage < galleryTotalPages) { galleryPage++; cargarGaleria(); }
        });

        async function cargarGaleria() {
            galleryLoading.classList.remove('hidden');
            galleryGrid.innerHTML = '';
            galleryEmpty.classList.add('hidden');
            galleryPagination.classList.add('hidden');

            const params = new URLSearchParams({
                page: galleryPage,
                per_page: 20,
                search: gallerySearch.value,
                universo: galleryUniverso.value,
                estado: galleryEstado.value,
                fecha_desde: galleryFechaDesde.value,
                fecha_hasta: galleryFechaHasta.value,
                sort: gallerySort.value,
                order: gallerySort.value === 'fecha_descarga' || gallerySort.value === 'total_paginas' ? 'DESC' : 'ASC'
            });

            try {
                const res = await fetch('gallery.php?' + params.toString());
                const data = await res.json();

                galleryLoading.classList.add('hidden');

                // ── Mostrar contador de eliminados ──
                const deletedCount = document.getElementById('galleryDeletedCount');
                if (data.total_eliminados && data.total_eliminados > 0) {
                    deletedCount.textContent = `🗑 ${data.total_eliminados} manga(s) eliminado(s)`;
                    deletedCount.classList.remove('hidden');
                } else {
                    deletedCount.classList.add('hidden');
                }

                if (!data.success || !data.comics || data.comics.length === 0) {
                    galleryEmpty.classList.remove('hidden');
                    galleryCount.classList.add('hidden');
                    return;
                }

                galleryCount.textContent = data.total;
                galleryCount.classList.remove('hidden');

                if (data.universos) {
                    const currentVal = galleryUniverso.value;
                    galleryUniverso.innerHTML = '<option value="">Todos</option>';
                    data.universos.forEach(u => {
                        const opt = document.createElement('option');
                        opt.value = u;
                        opt.textContent = u;
                        if (u === currentVal) opt.selected = true;
                        galleryUniverso.appendChild(opt);
                    });
                }

                galleryGrid.innerHTML = '';
                data.comics.forEach(comic => {
                    const card = document.createElement('div');
                    card.className = 'comic-card';

                    const estadoClass = 'badge-' + (comic.estado || 'descargando');

                    // ── Renderizar badges de taxonomía ──
                    let taxHtml = '';
                    if (comic.taxonomias) {
                        const tx = comic.taxonomias;
                        // Autores / Artistas
                        if (tx.autores && tx.autores.length > 0) {
                            tx.autores.forEach(a => {
                                taxHtml += `<span class="tax-badge tax-author" title="Autor">✍️ ${a}</span> `;
                            });
                        }
                        // Personajes (máximo 3)
                        if (tx.personajes && tx.personajes.length > 0) {
                            const maxChars = 3;
                            const showChars = tx.personajes.slice(0, maxChars);
                            showChars.forEach(p => {
                                taxHtml += `<span class="tax-badge tax-character" title="Personaje">👤 ${p}</span> `;
                            });
                            if (tx.personajes.length > maxChars) {
                                taxHtml += `<span class="tax-badge tax-more" title="Ver más personajes">+${tx.personajes.length - maxChars}</span> `;
                            }
                        }
                        // Idioma
                        if (tx.idioma) {
                            taxHtml += `<span class="tax-badge tax-lang" title="Idioma">🌐 ${tx.idioma}</span> `;
                        }
                        // Tipos
                        if (tx.tipos && tx.tipos.length > 0) {
                            tx.tipos.forEach(t => {
                                taxHtml += `<span class="tax-badge tax-type" title="Tipo">📁 ${t}</span> `;
                            });
                        }
                        // Etiquetas (máximo 4)
                        if (tx.etiquetas && tx.etiquetas.length > 0) {
                            const maxTags = 4;
                            const showTags = tx.etiquetas.slice(0, maxTags);
                            showTags.forEach(t => {
                                taxHtml += `<span class="tax-badge tax-tag" title="Etiqueta">${t}</span> `;
                            });
                            if (tx.etiquetas.length > maxTags) {
                                taxHtml += `<span class="tax-badge tax-more" title="Ver más etiquetas">+${tx.etiquetas.length - maxTags}</span> `;
                            }
                        }
                    }

                    card.innerHTML = `
                        ${comic.portada
                            ? `<img src="${comic.portada}" alt="${comic.titulo}" class="cover" loading="lazy">`
                            : `<div class="cover-placeholder">📖</div>`
                        }
                        <div class="p-3">
                            <h3 class="text-sm font-semibold text-gray-200 truncate" title="${comic.titulo}">${comic.titulo}</h3>
                            <p class="text-xs text-gray-500 truncate">ID ${comic.id_fuente}${comic.universo ? ' · ' + comic.universo : ''}</p>
                            ${taxHtml ? `<div class="flex flex-wrap gap-1 mt-1.5">${taxHtml}</div>` : ''}
                            <div class="flex items-center justify-between mt-2">
                                <span class="badge ${estadoClass}">${comic.estado}</span>
                                <span class="text-xs text-gray-500">${comic.paginas_ok || 0}/${comic.total_paginas || '?'}</span>
                            </div>
                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-800/50">
                                <span class="text-xs text-gray-600">${comic.tamano_formateado || ''}</span>
                                <div class="flex items-center gap-1">
                                    <button class="btn-tax-view open-tax-modal"
                                            data-comic='${comic.taxonomias ? JSON.stringify(comic.taxonomias).replace(/'/g, "'") : "null"}'
                                            data-titulo="${comic.titulo.replace(/"/g, '"')}"
                                            title="Ver todas las taxonomías de este cómic">
                                        🏷️
                                    </button>
                                    <button class="btn-delete-comic text-red-500 hover:text-red-400 text-xs px-2 py-1 rounded transition-colors hover:bg-red-900/20"
                                            data-id="${comic.id_fuente}"
                                            data-titulo="${comic.titulo.replace(/"/g, '"')}"
                                            title="Eliminar este manga permanentemente">
                                        🗑
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;

                    // Click en la tarjeta abre el visor (excepto si es en botones de acción)
                    card.addEventListener('click', (e) => {
                        if (e.target.closest('.btn-delete-comic')) return;
                        if (e.target.closest('.open-tax-modal')) return;
                        abrirVisor(comic.id_fuente, comic.titulo);
                    });

                    galleryGrid.appendChild(card);
                });

                galleryTotalPages = data.total_pages || 1;
                if (galleryTotalPages > 1) {
                    galleryPagination.classList.remove('hidden');
                    pageInfo.textContent = `Página ${data.page} de ${galleryTotalPages} (${data.total} cómics)`;
                    prevPageBtn.disabled = data.page <= 1;
                    nextPageBtn.disabled = data.page >= galleryTotalPages;
                }

            } catch (err) {
                galleryLoading.classList.add('hidden');
                galleryGrid.innerHTML = `<div class="col-span-full text-center py-8 text-red-400">Error al cargar: ${err.message}</div>`;
            }
        }


        // ════════════════════════════════════════════════════════════
        //  VISOR
        // ════════════════════════════════════════════════════════════

        async function abrirVisor(comicId, titulo) {
            viewerModal.classList.add('open');
            viewerTitle.textContent = `Cargando ${titulo}...`;
            viewerPageInfo.textContent = '';
            viewerImage.src = '';
            viewerPages = [];
            viewerCurrent = 0;
            viewerZoom = 1;
            viewerFit = false;
            viewerImage.style.transform = 'scale(1)';

            try {
                const res = await fetch('viewer.php?comic_id=' + comicId);
                const data = await res.json();

                if (!data.success) {
                    viewerTitle.textContent = 'Error: ' + (data.message || 'No disponible');
                    return;
                }

                viewerPages = data.paginas;
                viewerTitle.textContent = `${data.comic.titulo} (ID ${data.comic.id_fuente})`;
                viewerCurrent = 0;
                mostrarPagina(0);

            } catch (err) {
                viewerTitle.textContent = 'Error al cargar: ' + err.message;
            }
        }

        function mostrarPagina(idx) {
            if (idx < 0 || idx >= viewerPages.length) return;
            viewerCurrent = idx;
            const page = viewerPages[idx];
            // Usar page.url que ya viene con viewer.php?comic_id=X&page=N (evita problemas de ruta)
            viewerImage.src = page.url;
            viewerPageInfo.textContent = `Página ${idx + 1} de ${viewerPages.length}`;
            viewerZoom = 1;
            viewerImage.style.transform = 'scale(1)';
        }

        viewerPrev.addEventListener('click', () => mostrarPagina(viewerCurrent - 1));
        viewerNext.addEventListener('click', () => mostrarPagina(viewerCurrent + 1));

        document.addEventListener('keydown', (e) => {
            if (!viewerModal.classList.contains('open')) return;
            if (e.key === 'Escape') viewerModal.classList.remove('open');
            if (e.key === 'ArrowLeft') mostrarPagina(viewerCurrent - 1);
            if (e.key === 'ArrowRight') mostrarPagina(viewerCurrent + 1);
        });

        viewerClose.addEventListener('click', () => viewerModal.classList.remove('open'));

        viewerBody.addEventListener('click', (e) => {
            if (e.target === viewerBody) viewerModal.classList.remove('open');
        });

        viewerZoomIn.addEventListener('click', () => {
            viewerZoom = Math.min(4, viewerZoom + 0.25);
            viewerImage.style.transform = `scale(${viewerZoom})`;
            viewerFit = false;
        });
        viewerZoomOut.addEventListener('click', () => {
            viewerZoom = Math.max(0.25, viewerZoom - 0.25);
            viewerImage.style.transform = `scale(${viewerZoom})`;
            viewerFit = false;
        });
        viewerFitWidth.addEventListener('click', () => {
            if (viewerFit) {
                viewerZoom = 1;
                viewerImage.style.transform = 'scale(1)';
                viewerImage.style.maxWidth = '';
                viewerFit = false;
            } else {
                viewerImage.style.maxWidth = '100%';
                viewerImage.style.transform = 'scale(1)';
                viewerFit = true;
            }
        });

        viewerBody.addEventListener('wheel', (e) => {
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault();
                if (e.deltaY < 0) {
                    viewerZoom = Math.min(4, viewerZoom + 0.1);
                } else {
                    viewerZoom = Math.max(0.25, viewerZoom - 0.1);
                }
                viewerImage.style.transform = `scale(${viewerZoom})`;
                viewerFit = false;
            }
        }, { passive: false });


        // ════════════════════════════════════════════════════════════
        //  TAXONOMY MODAL
        // ════════════════════════════════════════════════════════════

        const taxModal       = document.getElementById('taxModal');
        const taxModalBody   = document.getElementById('taxModalBody');
        const taxModalTitle  = document.getElementById('taxModalTitle');
        const taxModalClose  = document.getElementById('taxModalClose');

        function abrirModalTaxonomias(taxonomias, titulo) {
            taxModalTitle.innerHTML = `<span>🏷️</span> Taxonomías: <span class="text-indigo-400 text-sm font-normal truncate max-w-[250px] inline-block align-middle">${titulo}</span>`;

            let html = '';

            // ── Idioma ──
            html += `<div class="tax-section">
                <div class="tax-section-title">🌐 Idioma</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.idioma) {
                html += `<span class="tax-item tax-item-lang">${taxonomias.idioma}</span>`;
            } else {
                html += `<span class="tax-empty">No especificado</span>`;
            }
            html += `</div></div>`;

            // ── Universos ──
            html += `<div class="tax-section">
                <div class="tax-section-title">🌌 Universos</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.universos && taxonomias.universos.length > 0) {
                taxonomias.universos.forEach(u => {
                    html += `<span class="tax-item tax-item-universe">${u}</span>`;
                });
            } else {
                html += `<span class="tax-empty">No hay universos</span>`;
            }
            html += `</div></div>`;

            // ── Tipos ──
            html += `<div class="tax-section">
                <div class="tax-section-title">📁 Tipos</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.tipos && taxonomias.tipos.length > 0) {
                taxonomias.tipos.forEach(t => {
                    html += `<span class="tax-item tax-item-type">${t}</span>`;
                });
            } else {
                html += `<span class="tax-empty">No hay tipos</span>`;
            }
            html += `</div></div>`;

            // ── Autores ──
            html += `<div class="tax-section">
                <div class="tax-section-title">✍️ Autores</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.autores && taxonomias.autores.length > 0) {
                taxonomias.autores.forEach(a => {
                    html += `<span class="tax-item tax-item-author">${a}</span>`;
                });
            } else {
                html += `<span class="tax-empty">No hay autores</span>`;
            }
            html += `</div></div>`;

            // ── Personajes ──
            html += `<div class="tax-section">
                <div class="tax-section-title">👤 Personajes (${taxonomias && taxonomias.personajes ? taxonomias.personajes.length : 0})</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.personajes && taxonomias.personajes.length > 0) {
                taxonomias.personajes.forEach(p => {
                    html += `<span class="tax-item tax-item-character">${p}</span>`;
                });
            } else {
                html += `<span class="tax-empty">No hay personajes</span>`;
            }
            html += `</div></div>`;

            // ── Etiquetas ──
            html += `<div class="tax-section">
                <div class="tax-section-title">🏷️ Etiquetas (${taxonomias && taxonomias.etiquetas ? taxonomias.etiquetas.length : 0})</div>
                <div class="tax-items">`;
            if (taxonomias && taxonomias.etiquetas && taxonomias.etiquetas.length > 0) {
                taxonomias.etiquetas.forEach(t => {
                    html += `<span class="tax-item tax-item-tag">${t}</span>`;
                });
            } else {
                html += `<span class="tax-empty">No hay etiquetas</span>`;
            }
            html += `</div></div>`;

            taxModalBody.innerHTML = html;
            taxModal.classList.add('open');
        }

        // ── Delegación de eventos para botones "Ver Taxonomías" ──
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.open-tax-modal');
            if (!btn) return;

            const taxRaw   = btn.dataset.comic;
            const titulo   = btn.dataset.titulo || 'Cómic';
            let taxonomias = null;

            try {
                taxonomias = JSON.parse(taxRaw);
            } catch (err) {
                console.warn('Error al parsear taxonomías:', err);
            }

            if (!taxonomias) {
                alert('No hay datos de taxonomías disponibles para este cómic.');
                return;
            }

            abrirModalTaxonomias(taxonomias, titulo);
        });

        // ── Cerrar modal ──
        taxModalClose.addEventListener('click', () => {
            taxModal.classList.remove('open');
        });

        taxModal.addEventListener('click', (e) => {
            if (e.target === taxModal) {
                taxModal.classList.remove('open');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && taxModal.classList.contains('open')) {
                taxModal.classList.remove('open');
            }
        });


        // ════════════════════════════════════════════════════════════
        //  ELIMINAR MANGA (con confirmación y blacklist permanente)
        // ════════════════════════════════════════════════════════════

        // ── Delegación de eventos para botones "Eliminar" ──
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-delete-comic');
            if (!btn) return;

            const id      = parseInt(btn.dataset.id);
            const titulo  = btn.dataset.titulo;

            if (!id || !titulo) return;

            // ── Confirmación ──
            if (!confirm(`⚠️ ¿Estás seguro de eliminar «${titulo}» (ID ${id})?\n\n` +
                '• Se eliminarán TODOS los archivos del disco\n' +
                '• Se registrará en la lista de eliminados\n' +
                '• No se podrá volver a descargar ni subir al servidor\n\n' +
                '¿Continuar?')) {
                return;
            }

            // ── Deshabilitar botón mientras se procesa ──
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="width:0.75rem;height:0.75rem"></span>';
            btn.classList.add('opacity-50', 'cursor-not-allowed');

            try {
                const formData = new FormData();
                formData.append('id_fuente', id);
                formData.append('motivo', 'Eliminado por usuario');

                const res = await fetch('delete_manga.php', {
                    method: 'POST',
                    body: formData,
                });

                const data = await res.json();

                if (data.success) {
                    // Mostrar feedback en la consola de log
                    appendToLog({
                        type: 'warning',
                        message: `🗑 ${data.message}`
                    });

                    // Recargar galería para reflejar cambios
                    cargarGaleria();
                } else {
                    alert('❌ Error: ' + (data.message || 'No se pudo eliminar'));
                    btn.disabled = false;
                    btn.innerHTML = '🗑 Eliminar';
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                }

            } catch (err) {
                alert('❌ Error de conexión: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '🗑 Eliminar';
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        });


        // ════════════════════════════════════════════════════════════
        //  DASHBOARD
        // ════════════════════════════════════════════════════════════

        async function cargarDashboard() {
            const container = document.getElementById('dashboardContent');
            container.innerHTML = '<div class="text-center py-12"><span class="spinner"></span><p class="text-gray-500 text-sm mt-3">Cargando estadísticas...</p></div>';

            try {
                const res = await fetch('dashboard.php');
                const data = await res.json();

                if (!data.success) {
                    container.innerHTML = `<div class="text-center py-8 text-red-400">Error: ${data.message}</div>`;
                    return;
                }

                const s = data.stats;

                container.innerHTML = `
                    <!-- Stats Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <div class="stat-card">
                            <p class="text-xs text-gray-500 uppercase tracking-wider">Total Cómics</p>
                            <p class="stat-value">${s.total_comics}</p>
                        </div>
                        <div class="stat-card">
                            <p class="text-xs text-gray-500 uppercase tracking-wider">Páginas Descargadas</p>
                            <p class="stat-value">${s.total_paginas_ok.toLocaleString()}</p>
                            <p class="text-xs text-gray-600 mt-1">${s.total_paginas_fail > 0 ? s.total_paginas_fail + ' con error' : 'Sin errores'}</p>
                        </div>
                        <div class="stat-card">
                            <p class="text-xs text-gray-500 uppercase tracking-wider">Tamaño en Disco</p>
                            <p class="stat-value">${s.tamano_total_formateado}</p>
                            <p class="text-xs text-gray-600 mt-1">${(s.archivos_en_disco || 0).toLocaleString()} archivos</p>
                        </div>
                        <div class="stat-card">
                            <p class="text-xs text-gray-500 uppercase tracking-wider">Tasa de Éxito</p>
                            <p class="stat-value">${s.tasa_exito}%</p>
                            <p class="text-xs text-gray-600 mt-1">${s.total_universos} universos distintos</p>
                        </div>
                    </div>

                    <!-- Second row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="glass p-5">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                <span>📊</span> Estado de Cómics
                            </h3>
                            <div class="space-y-3">
                                ${renderEstadoBar('completo', s.por_estado?.completo || 0, s.total_comics, '#3fb950')}
                                ${renderEstadoBar('parcial', s.por_estado?.parcial || 0, s.total_comics, '#d29922')}
                                ${renderEstadoBar('error', s.por_estado?.error || 0, s.total_comics, '#f85149')}
                                ${renderEstadoBar('descargando', s.por_estado?.descargando || 0, s.total_comics, '#58a6ff')}
                            </div>
                        </div>

                        <div class="glass p-5">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                <span>🌌</span> Top Universos
                            </h3>
                            ${s.top_universos && s.top_universos.length > 0 ? `
                                <div class="space-y-2">
                                    ${s.top_universos.map(u => `
                                        <div class="flex items-center justify-between py-1">
                                            <span class="text-sm text-gray-300 truncate">${u.universo}</span>
                                            <span class="text-xs text-indigo-400 font-mono font-semibold">${u.cantidad}</span>
                                        </div>
                                    `).join('')}
                                </div>
                            ` : '<p class="text-gray-500 text-sm">Sin datos</p>'}
                        </div>
                    </div>

                    <!-- Third row: Taxonomías -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="glass p-5">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                <span>🏷️</span> Taxonomías
                            </h3>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-sm text-gray-400">Cómics con taxonomías</span>
                                    <span class="text-xs text-emerald-400 font-mono font-semibold">${s.comics_con_taxonomias || 0}</span>
                                </div>
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-sm text-gray-400">Cómics sin taxonomías</span>
                                    <span class="text-xs text-gray-500 font-mono font-semibold">${s.comics_sin_taxonomias || 0}</span>
                                </div>
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-sm text-gray-400">Etiquetas únicas</span>
                                    <span class="text-xs text-indigo-400 font-mono font-semibold">${s.total_etiquetas_unicas || 0}</span>
                                </div>
                            </div>
                        </div>

                        <div class="glass p-5">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                <span>🌐</span> Idiomas más comunes
                            </h3>
                            ${s.top_idiomas && s.top_idiomas.length > 0 ? `
                                <div class="space-y-2">
                                    ${s.top_idiomas.map(l => `
                                        <div class="flex items-center justify-between py-1">
                                            <span class="text-sm text-gray-300 truncate">${l.idioma}</span>
                                            <span class="text-xs text-blue-400 font-mono font-semibold">${l.cantidad}</span>
                                        </div>
                                    `).join('')}
                                </div>
                            ` : '<p class="text-gray-500 text-sm">Sin datos de idiomas</p>'}
                        </div>

                        <div class="glass p-5">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                                <span>📤</span> Exportar a WordPress
                            </h3>
                            <p class="text-xs text-gray-500 mb-3">Genera un archivo JSON con todas las taxonomías procesadas listas para importar en WordPress mediante CPT UI.</p>
                            <button onclick="window.open('export_wp.php', '_blank')"
                                    class="btn-glow w-full justify-center text-xs py-2">
                                📦 Generar Exportación
                            </button>
                        </div>
                    </div>

                    <!-- Recent -->
                    <div class="glass p-5 mb-6">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                            <span>🕐</span> Últimas Descargas
                        </h3>
                        ${s.ultimas_descargas && s.ultimas_descargas.length > 0 ? `
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-gray-500 text-xs uppercase tracking-wider">
                                            <th class="text-left py-2 pr-4 font-medium">ID</th>
                                            <th class="text-left py-2 pr-4 font-medium">Título</th>
                                            <th class="text-left py-2 pr-4 font-medium">Universo</th>
                                            <th class="text-center py-2 pr-4 font-medium">Páginas</th>
                                            <th class="text-center py-2 pr-4 font-medium">Estado</th>
                                            <th class="text-right py-2 font-medium">Fecha</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${s.ultimas_descargas.map(c => `
                                            <tr class="border-t border-gray-800/50 hover:bg-white/[0.02] transition-colors">
                                                <td class="py-2.5 pr-4 text-indigo-400 font-mono text-xs">${c.id_fuente}</td>
                                                <td class="py-2.5 pr-4 text-gray-200">${c.titulo}</td>
                                                <td class="py-2.5 pr-4 text-gray-400">${c.universo || '-'}</td>
                                                <td class="py-2.5 pr-4 text-center text-gray-400">${c.paginas_ok}/${c.total_paginas}</td>
                                                <td class="py-2.5 pr-4 text-center"><span class="badge badge-${c.estado}">${c.estado}</span></td>
                                                <td class="py-2.5 text-right text-gray-500 text-xs">${c.fecha_descarga ? new Date(c.fecha_descarga).toLocaleDateString() : '-'}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<p class="text-gray-500 text-sm">Sin descargas aún</p>'}
                    </div>

                    <!-- System info -->
                    <div class="glass p-5">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 flex items-center gap-2">
                            <span>⚙️</span> Sistema
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <p class="text-gray-500 text-xs">Ruta descargas</p>
                                <p class="text-gray-300 truncate text-xs font-mono-custom" title="${s.ruta_descargas}">${s.ruta_descargas}</p>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs">Archivos en disco</p>
                                <p class="text-gray-300 text-sm font-semibold">${(s.archivos_en_disco || 0).toLocaleString()}</p>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs">Tamaño real disco</p>
                                <p class="text-gray-300 text-sm font-semibold">${s.tamano_real_formateado || '0 B'}</p>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs">Cómics totales</p>
                                <p class="text-gray-300 text-sm font-semibold">${s.total_comics}</p>
                            </div>
                        </div>
                    </div>
                `;

            } catch (err) {
                container.innerHTML = `<div class="text-center py-8 text-red-400">Error de conexión: ${err.message}</div>`;
            }
        }

        function renderEstadoBar(label, count, total, color) {
            if (total === 0) return '';
            const pct = Math.round((count / total) * 100);
            return `
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 w-20">${label}</span>
                    <div class="flex-1 h-2 bg-gray-900/50 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width:${pct}%; background:${color}; transition: width 0.6s ease"></div>
                    </div>
                    <span class="text-xs text-gray-500 w-16 text-right font-mono">${count} (${pct}%)</span>
                </div>
            `;
        }


        // ── Cargar galería al inicio ──
        setTimeout(() => cargarGaleria(), 500);


        // ════════════════════════════════════════════════════════════
        //  CONFIGURACIÓN (SETTINGS MODAL)
        // ════════════════════════════════════════════════════════════

        const settingsModal   = document.getElementById('settingsModal');
        const btnOpenSettings = document.getElementById('btnOpenSettings');
        const settingsClose   = document.getElementById('settingsClose');
        const settingsForm    = document.getElementById('settingsForm');
        const settingsMsg     = document.getElementById('settingsMsg');
        const btnSaveSettings = document.getElementById('btnSaveSettings');

        btnOpenSettings.addEventListener('click', () => {
            settingsModal.classList.add('open');
            cargarConfigActual();
        });

        settingsClose.addEventListener('click', () => settingsModal.classList.remove('open'));
        settingsModal.addEventListener('click', (e) => {
            if (e.target === settingsModal) settingsModal.classList.remove('open');
        });

        // Cargar configuración actual
        async function cargarConfigActual() {
            try {
                const res = await fetch('save_config.php');
                const data = await res.json();
                if (data.success && data.config) {
                    const c = data.config;
                    document.getElementById('cfg_db_host').value = c.db_host || 'localhost';
                    document.getElementById('cfg_db_name').value = c.db_name || 'comics_db';
                    document.getElementById('cfg_db_user').value = c.db_user || 'root';
                    document.getElementById('cfg_site_url').value = c.site_base_url || 'https://sitio.com';
                    document.getElementById('cfg_view_path').value = c.site_view_path || '/view';
                    document.getElementById('cfg_batch_path').value = c.site_batch_path || '/parody';
                    document.getElementById('cfg_download_path').value = c.download_path || '';
                    document.getElementById('cfg_delay_p_min').value = c.delay_page_min || 1.5;
                    document.getElementById('cfg_delay_p_max').value = c.delay_page_max || 3.5;
                    document.getElementById('cfg_delay_c_min').value = c.delay_comic_min || 5;
                    document.getElementById('cfg_delay_c_max').value = c.delay_comic_max || 10;
                    document.getElementById('cfg_retries').value = c.max_retries || 3;
                    document.getElementById('cfg_wp_url').value = c.wp_base_url || 'http://localhost:10003';
                    document.getElementById('cfg_wp_user').value = c.wp_username || 'admin';
                    if (c.wp_app_password_hint) {
                        document.getElementById('cfg_wp_pass').placeholder = c.wp_app_password_hint;
                    }
                    if (c.db_pass_hint) {
                        document.getElementById('cfg_db_pass').placeholder = c.db_pass_hint;
                    }
                }
                settingsMsg.classList.add('hidden');
            } catch (err) {
                settingsMsg.className = 'text-sm p-3 rounded-lg bg-red-900/30 border border-red-500/30 text-red-400';
                settingsMsg.textContent = 'Error al cargar configuración: ' + err.message;
                settingsMsg.classList.remove('hidden');
            }
        }

        // Guardar configuración
        settingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            btnSaveSettings.disabled = true;
            btnSaveSettings.innerHTML = '<span class="spinner"></span> Guardando...';
            settingsMsg.classList.add('hidden');

            const formData = new FormData();
            formData.append('db_host', document.getElementById('cfg_db_host').value);
            formData.append('db_name', document.getElementById('cfg_db_name').value);
            formData.append('db_user', document.getElementById('cfg_db_user').value);
            formData.append('db_pass', document.getElementById('cfg_db_pass').value);
            formData.append('site_base_url', document.getElementById('cfg_site_url').value);
            formData.append('site_view_path', document.getElementById('cfg_view_path').value);
            formData.append('site_batch_path', document.getElementById('cfg_batch_path').value);
            formData.append('download_path', document.getElementById('cfg_download_path').value);
            formData.append('delay_page_min', document.getElementById('cfg_delay_p_min').value);
            formData.append('delay_page_max', document.getElementById('cfg_delay_p_max').value);
            formData.append('delay_comic_min', document.getElementById('cfg_delay_c_min').value);
            formData.append('delay_comic_max', document.getElementById('cfg_delay_c_max').value);
            formData.append('max_retries', document.getElementById('cfg_retries').value);
            formData.append('wp_base_url', document.getElementById('cfg_wp_url').value);
            formData.append('wp_username', document.getElementById('cfg_wp_user').value);
            formData.append('wp_app_password', document.getElementById('cfg_wp_pass').value);

            try {
                const res = await fetch('save_config.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    settingsMsg.className = 'text-sm p-3 rounded-lg bg-green-900/30 border border-green-500/30 text-green-400';
                    settingsMsg.textContent = '✅ ' + data.message;
                    setTimeout(() => settingsModal.classList.remove('open'), 1500);
                } else {
                    settingsMsg.className = 'text-sm p-3 rounded-lg bg-red-900/30 border border-red-500/30 text-red-400';
                    settingsMsg.textContent = '❌ ' + (data.message || 'Error al guardar');
                }
                settingsMsg.classList.remove('hidden');
            } catch (err) {
                settingsMsg.className = 'text-sm p-3 rounded-lg bg-red-900/30 border border-red-500/30 text-red-400';
                settingsMsg.textContent = '❌ Error de red: ' + err.message;
                settingsMsg.classList.remove('hidden');
            }

            btnSaveSettings.disabled = false;
            btnSaveSettings.innerHTML = '💾 Guardar Configuración';
        });

    })();
    </script>

</body>
</html>
