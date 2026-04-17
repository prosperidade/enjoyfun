<!-- Incoming Friend Ping Notification -->
<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&amp;family=Manrope:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            "colors": {
                    "on-primary": "#361083",
                    "primary-dim": "#a88cfb",
                    "primary-fixed": "#ab8ffe",
                    "on-secondary": "#005e40",
                    "inverse-surface": "#fff7ff",
                    "on-tertiary-fixed": "#200051",
                    "on-tertiary-container": "#ffffff",
                    "on-primary-container": "#290070",
                    "on-tertiary-fixed-variant": "#4a00a4",
                    "on-tertiary": "#2b0065",
                    "tertiary-fixed-dim": "#b48fff",
                    "on-primary-fixed": "#000000",
                    "tertiary-fixed": "#c0a0ff",
                    "error-dim": "#d73357",
                    "outline-variant": "#514166",
                    "on-background": "#efdfff",
                    "on-primary-fixed-variant": "#330b80",
                    "tertiary": "#af88ff",
                    "surface-container-low": "#1b0a31",
                    "background": "#150629",
                    "on-secondary-fixed-variant": "#006948",
                    "secondary": "#68fcbf",
                    "on-surface": "#efdfff",
                    "error-container": "#a70138",
                    "secondary-container": "#006c4b",
                    "on-secondary-container": "#e0ffec",
                    "surface": "#150629",
                    "tertiary-dim": "#8a4cfc",
                    "on-surface-variant": "#b7a3cf",
                    "surface-container-highest": "#301a4d",
                    "primary-container": "#ab8ffe",
                    "surface-variant": "#301a4d",
                    "on-secondary-fixed": "#004931",
                    "on-error-container": "#ffb2b9",
                    "primary-fixed-dim": "#9d81f0",
                    "surface-dim": "#150629",
                    "surface-container-high": "#291543",
                    "surface-container": "#22103a",
                    "tertiary-container": "#8342f4",
                    "surface-bright": "#372056",
                    "surface-tint": "#b79fff",
                    "secondary-fixed-dim": "#57edb1",
                    "primary": "#b79fff",
                    "on-error": "#490013",
                    "secondary-dim": "#57edb1",
                    "secondary-fixed": "#68fcbf",
                    "inverse-primary": "#684cb6",
                    "surface-container-lowest": "#000000",
                    "inverse-on-surface": "#5e4e74",
                    "error": "#ff6e84",
                    "outline": "#806e96"
            },
            "borderRadius": {
                    "DEFAULT": "0.125rem",
                    "lg": "0.25rem",
                    "xl": "0.5rem",
                    "full": "0.75rem"
            },
            "fontFamily": {
                    "headline": ["Space Grotesk"],
                    "body": ["Manrope"],
                    "label": ["Space Grotesk"]
            }
          },
        },
      }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .ripple-container {
            position: relative;
            overflow: visible;
        }
        .ripple-ring {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 2px solid #b79fff33;
            border-radius: 9999px;
            width: 100%;
            height: 100%;
            opacity: 0;
            pointer-events: none;
        }
        /* Keeping CSS purely for the layout/overlay logic as requested */
        .glass-blur {
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
    </style>
<style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head>
<body class="bg-background text-on-background font-body min-h-screen overflow-hidden">
<!-- Map Background Section -->
<div class="fixed inset-0 z-0">
<div class="absolute inset-0 bg-gradient-to-b from-transparent to-background/80 z-10"></div>
<img class="w-full h-full object-cover grayscale opacity-40" data-alt="Dark stylized futuristic map with neon violet glowing points and high-contrast topography in a deep space blue environment" data-location="Neo-Tokyo" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBynfe-VZsT6xgWDbt1IQ23t13kwLMnlua7nOA7zeeGWg3_oNlClzARFu6_SzFGucZ8odwOLijWri6EOWNijr518WXdVK8NU698bResxNzk_CgK_Je0UNtF0KTiE2zVLyjpAdfOqg609SrWl_Zox8xHtFe1-87c-E559vKsC7hNWbf-Z4Lf-BkPywvoWWP-NO-z6nlVpbM2mKRj4aGQ6v6iuudHsCuYWyrV0BSK-NmCODja5F0PPnf8j6tgZH7utoDIXd9WGcnXkCY"/>
<!-- Synthetic Map Elements -->
<div class="absolute top-1/3 left-1/4 w-3 h-3 bg-primary rounded-full shadow-[0_0_15px_#b79fff]"></div>
<div class="absolute top-1/2 left-2/3 w-3 h-3 bg-secondary rounded-full shadow-[0_0_15px_#68fcbf]"></div>
</div>
<!-- Top Navigation Anchor (Shared Component) -->
<header class="fixed top-0 w-full z-50 flex justify-between items-center px-6 py-4 bg-transparent backdrop-blur-xl shadow-[0_0_40px_rgba(167,139,250,0.12)]">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined text-violet-400">menu</span>
<h1 class="text-xl font-black tracking-tighter text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-violet-200 font-headline">ENJOYFUN</h1>
</div>
<div class="w-10 h-10 rounded-full border-2 border-violet-400/30 p-0.5">
<img class="w-full h-full rounded-full object-cover" data-alt="High-end 3D avatar profile picture of a tech enthusiast with neon lighting highlights" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDl1MfKAiiGEL60e9I_HCISn608H3Yrt0Ngvdv_IZxkkVc0Mc-s0SMhvzqCSgEGUr_OAYw2-apbXOhG96FjrH1GS7Bd0oQMPEG7rgpmkc1FYE23g8o117UGvWFJZ9RY8S2NHw39M8fwGLdhn5NtK7npnqNjzyJrP4SDzQQf5ts8ynuwz_cpjwsmZ2Njrd6neYcvmHsk_9KhKgoEtGuvFti5OxytxyaRDUV4De8j_KDnGI1feLOM98CSDRdGwg0yZEZ6lKYl8xchM6A"/>
</div>
</header>
<!-- Notification Overlay Layer -->
<main class="relative z-40 min-h-screen w-full flex flex-col items-center justify-center p-6">
<!-- Ripple Effect Container -->
<div class="ripple-container flex items-center justify-center">
<!-- The Ping Notification Card -->
<div class="relative w-full max-w-sm rounded-xl overflow-hidden border border-outline-variant/15 glass-blur bg-surface-variant/40 shadow-[0_40px_100px_rgba(0,0,0,0.6)] group">
<!-- Glowing Accent -->
<div class="absolute -top-10 -left-10 w-32 h-32 bg-primary/20 rounded-full blur-3xl transition-all duration-700 group-hover:bg-primary/40"></div>
<div class="relative p-6 flex flex-col items-center text-center">
<!-- Sender Avatar Cluster -->
<div class="relative mb-6">
<!-- Orbiting Pulse -->
<div class="absolute -inset-4 border-2 border-secondary/20 rounded-full"></div>
<div class="absolute inset-0 bg-secondary/10 rounded-full blur-xl"></div>
<div class="relative w-24 h-24 rounded-full border-2 border-secondary shadow-[0_0_20px_#68fcbf44]">
<img class="w-full h-full rounded-full object-cover" data-alt="Young woman named Luna with cybernetic aesthetic makeup and neon hair highlights smiling" src="https://lh3.googleusercontent.com/aida-public/AB6AXuAD95dm1V1bMykdpLlBXUCr2GDTylI2MV70oDXie0yC4Xw6hJPkZ4jzLsEHjx_fkltF4eW16k8jl4sEc-MiQcZPIWJf8I0WP7LFAP--V3aM-c1Ady2BfApGz9ksnmP1HPSvgyY1gjt3KBu1n0DdtOH7EweNtdXOs3SkmAkc-GdctI6t1xl5Zu6UU5pkPTyG5CJTvA5XVYL-KP1OB52vyiDoWWRWBmH950wnGXk7wnLEGrnSMGbTXw6HO_fjAva-odzZXecxicDfj5w"/>
<!-- Status Indicator -->
<div class="absolute bottom-1 right-1 w-6 h-6 bg-secondary border-4 border-surface rounded-full shadow-[0_0_10px_#68fcbf]"></div>
</div>
</div>
<!-- Content -->
<div class="space-y-2 mb-8">
<span class="text-secondary font-label uppercase tracking-widest text-[10px] bg-secondary/10 px-3 py-1 rounded-full border border-secondary/20">Active Discovery</span>
<h2 class="text-2xl font-bold font-headline text-on-surface tracking-tight">Luna is pinging you!</h2>
<p class="text-on-surface-variant font-body text-sm leading-relaxed max-w-[240px] mx-auto">
                            Luna found a hidden holographic gallery nearby. Ready to explore?
                        </p>
</div>
<!-- Action Cluster -->
<div class="w-full flex flex-col gap-3">
<button class="w-full py-4 rounded-xl bg-gradient-to-br from-primary to-primary-container text-on-primary font-bold font-headline transition-all active:scale-95 shadow-[0_8px_30px_rgba(183,159,255,0.3)] flex items-center justify-center gap-2">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">bolt</span>
                            Join them
                        </button>
<button class="w-full py-3 rounded-xl border border-outline-variant/20 text-on-surface-variant font-medium hover:bg-surface-variant/50 transition-colors">
                            Dismiss
                        </button>
</div>
</div>
</div>
</div>
<!-- Location Detail Hint -->
<div class="mt-12 flex items-center gap-3 glass-blur bg-surface-container-low/60 px-4 py-2 rounded-full border border-outline-variant/10">
<span class="material-symbols-outlined text-secondary text-sm">location_on</span>
<span class="text-on-surface-variant text-xs font-label">NEO-SHIBUYA SECTOR 7</span>
</div>
</main>
<!-- Bottom Navigation Shell (Shared Component) -->
<nav class="fixed bottom-6 left-1/2 -translate-x-1/2 w-[90%] max-w-md bg-slate-900/40 backdrop-blur-2xl rounded-2xl border border-violet-400/15 flex justify-around items-center px-4 py-2 z-50 shadow-[0_20px_50px_rgba(0,0,0,0.5)]">
<div class="text-slate-400 p-3 hover:text-violet-300 active:scale-90 transition-transform">
<span class="material-symbols-outlined">style</span>
</div>
<!-- Active Tab: Map -->
<div class="bg-gradient-to-br from-violet-400 to-violet-600 text-white rounded-xl p-3 shadow-[0_0_15px_rgba(167,139,250,0.4)] active:scale-90 transition-transform">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">map</span>
</div>
<div class="text-slate-400 p-3 hover:text-violet-300 active:scale-90 transition-transform">
<span class="material-symbols-outlined">smart_toy</span>
</div>
<div class="text-slate-400 p-3 hover:text-violet-300 active:scale-90 transition-transform">
<span class="material-symbols-outlined">event_note</span>
</div>
</nav>
<!-- Background UI Elements (Floating Bits) -->
<div class="fixed top-24 left-10 pointer-events-none opacity-20 hidden md:block">
<div class="p-4 rounded-xl border border-outline-variant/20 glass-blur">
<div class="w-32 h-2 bg-outline-variant/30 rounded-full mb-2"></div>
<div class="w-24 h-2 bg-outline-variant/30 rounded-full"></div>
</div>
</div>
</body></html>

<!-- Refined Teleportation Transition -->
<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Teleporting...</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&amp;family=Manrope:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "on-secondary-fixed": "#004931",
                        "primary-container": "#ab8ffe",
                        "surface-tint": "#b79fff",
                        "surface-container-lowest": "#000000",
                        "primary-dim": "#a88cfb",
                        "surface-variant": "#301a4d",
                        "primary": "#b79fff",
                        "on-error": "#490013",
                        "error-dim": "#d73357",
                        "inverse-surface": "#fff7ff",
                        "secondary-container": "#006c4b",
                        "tertiary": "#af88ff",
                        "on-secondary-container": "#e0ffec",
                        "on-surface": "#efdfff",
                        "outline": "#806e96",
                        "primary-fixed": "#ab8ffe",
                        "on-error-container": "#ffb2b9",
                        "on-primary-fixed-variant": "#330b80",
                        "surface-container-high": "#291543",
                        "surface-dim": "#150629",
                        "on-tertiary": "#2b0065",
                        "on-secondary-fixed-variant": "#006948",
                        "secondary-fixed": "#68fcbf",
                        "surface-container-low": "#1b0a31",
                        "surface-bright": "#372056",
                        "on-primary-fixed": "#000000",
                        "surface": "#150629",
                        "on-primary": "#361083",
                        "primary-fixed-dim": "#9d81f0",
                        "tertiary-fixed": "#c0a0ff",
                        "tertiary-container": "#8342f4",
                        "background": "#150629",
                        "secondary": "#68fcbf",
                        "error": "#ff6e84",
                        "tertiary-dim": "#8a4cfc",
                        "surface-container-highest": "#301a4d",
                        "on-tertiary-fixed": "#200051",
                        "on-tertiary-container": "#ffffff",
                        "secondary-dim": "#57edb1",
                        "on-primary-container": "#290070",
                        "surface-container": "#22103a",
                        "on-surface-variant": "#b7a3cf",
                        "inverse-primary": "#684cb6",
                        "on-secondary": "#005e40",
                        "secondary-fixed-dim": "#57edb1",
                        "inverse-on-surface": "#5e4e74",
                        "tertiary-fixed-dim": "#b48fff",
                        "on-tertiary-fixed-variant": "#4a00a4",
                        "on-background": "#efdfff",
                        "outline-variant": "#514166",
                        "error-container": "#a70138"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.125rem",
                        "lg": "0.25rem",
                        "xl": "0.5rem",
                        "full": "0.75rem"
                    },
                    "fontFamily": {
                        "headline": ["Space Grotesk"],
                        "body": ["Manrope"],
                        "label": ["Space Grotesk"]
                    },
                    "animation": {
                        "glitch": "glitch 1s linear infinite",
                        "header-pulse": "header-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite",
                        "speed-scroll": "speed-scroll 0.5s linear infinite",
                        "chromatic": "chromatic 4s infinite"
                    },
                    "keyframes": {
                        "glitch": {
                            "0%, 100%": { transform: "translate(0)" },
                            "33%": { transform: "translate(-1px, 0.5px)" },
                            "66%": { transform: "translate(1px, -0.5px)" }
                        },
                        "header-pulse": {
                            "0%, 100%": { opacity: "1", filter: "drop-shadow(0 0 2px #c4b5fd)" },
                            "50%": { opacity: "0.7", filter: "drop-shadow(0 0 12px #a78bfa)" }
                        },
                        "speed-scroll": {
                            "0%": { transform: "translateX(100%) skewX(-20deg)", opacity: "0" },
                            "50%": { opacity: "0.5" },
                            "100%": { transform: "translateX(-200%) skewX(-20deg)", opacity: "0" }
                        },
                        "chromatic": {
                            "0%, 100%": { filter: "hue-rotate(0deg)" },
                            "50%": { filter: "hue-rotate(15deg) contrast(1.1)" }
                        }
                    }
                },
            },
        }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .perspective-dive {
            perspective: 1000px;
            transform-style: preserve-3d;
        }
        .isometric-grid {
            background-image: 
                linear-gradient(to right, rgba(183, 159, 255, 0.1) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(183, 159, 255, 0.1) 1px, transparent 1px);
            background-size: 60px 60px;
            transform: rotateX(60deg) rotateZ(-45deg) scale(2.5);
            width: 200%;
            height: 200%;
            position: absolute;
            top: -50%;
            left: -50%;
        }
        .speed-line-dynamic {
            position: absolute;
            background: linear-gradient(to right, transparent, #b79fff, white, #b79fff, transparent);
            height: 1px;
            pointer-events: none;
        }
        .bokeh {
            filter: blur(40px);
            border-radius: 50%;
            position: absolute;
        }
        .chromatic-aberration {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 99;
            box-shadow: inset 0 0 100px rgba(183, 159, 255, 0.15),
                        inset 0 0 40px rgba(104, 252, 191, 0.1);
            mix-blend-mode: screen;
        }
        .avatar-radial-blur {
            position: absolute;
            inset: -40px;
            background: radial-gradient(circle, transparent 40%, rgba(183, 159, 255, 0.4) 100%);
            filter: blur(20px);
            animation: pulse 2s infinite ease-in-out;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.4; }
            50% { transform: scale(1.15); opacity: 0.7; }
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 100;
            background: radial-gradient(circle at center, transparent 30%, rgba(104, 252, 191, 0.05) 70%, rgba(183, 159, 255, 0.1) 100%);
            pointer-events: none;
        }
    </style>
<style>
        body {
          min-height: max(884px, 100dvh);
        }
    </style>
</head>
<body class="bg-surface text-on-surface font-body overflow-hidden selection:bg-secondary selection:text-on-secondary animate-chromatic">
<!-- Chromatic Aberration Overlay -->
<div class="chromatic-aberration"></div>
<!-- Top Navigation Anchor -->
<header class="fixed top-0 w-full z-[100] flex items-center justify-between px-6 h-16 bg-[#150629]/40 backdrop-blur-xl no-border bg-gradient-to-b from-[#150629] to-transparent shadow-[0_0_20px_rgba(167,139,250,0.1)]">
<div class="flex items-center gap-4">
<button class="active:scale-95 transition-transform text-violet-400">
<span class="material-symbols-outlined">arrow_back</span>
</button>
<h1 class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-300 animate-header-pulse">TELEPORTING...</h1>
</div>
<div class="text-xl font-bold tracking-widest text-violet-300">NEXUS.OS</div>
</header>
<main class="relative h-screen w-full flex items-center justify-center overflow-hidden">
<!-- Background Immersive Environment -->
<div class="absolute inset-0 z-0 bg-[#150629]">
<div class="isometric-grid opacity-20"></div>
<!-- Dynamic Speed Lines -->
<div class="speed-line-dynamic top-[15%] w-[400px] opacity-20 animate-speed-scroll" style="animation-delay: 0.1s; right: -400px;"></div>
<div class="speed-line-dynamic top-[40%] w-[600px] opacity-40 animate-speed-scroll" style="animation-delay: 0.3s; right: -600px;"></div>
<div class="speed-line-dynamic top-[65%] w-[350px] opacity-15 animate-speed-scroll" style="animation-delay: 0.5s; right: -350px;"></div>
<div class="speed-line-dynamic top-[85%] w-[500px] opacity-30 animate-speed-scroll" style="animation-delay: 0.7s; right: -500px;"></div>
<div class="speed-line-dynamic top-[30%] w-[450px] opacity-25 animate-speed-scroll" style="animation-delay: 0s; right: -450px;"></div>
<div class="speed-line-dynamic top-[75%] w-[550px] opacity-35 animate-speed-scroll" style="animation-delay: 0.4s; right: -550px;"></div>
<!-- Bokeh Ambient Glows -->
<div class="bokeh w-64 h-64 bg-primary/15 top-20 left-20"></div>
<div class="bokeh w-96 h-96 bg-secondary/10 bottom-0 right-0"></div>
</div>
<!-- 3D Map Dive Content -->
<div class="perspective-dive relative z-10 w-full h-full flex items-center justify-center">
<!-- Perspective Path -->
<div class="absolute w-[800px] h-[400px] border-b-4 border-secondary/30 blur-sm transform rotateX(75deg) translateZ(-100px)"></div>
<div class="absolute w-2 h-[800px] bg-gradient-to-t from-secondary via-transparent to-transparent opacity-40 blur-md transform rotateX(75deg) origin-bottom"></div>
<!-- Glowing Focal Point (Friend Avatar) -->
<div class="relative flex flex-col items-center">
<div class="relative w-28 h-28 mb-6">
<!-- Intensified Radial Blur -->
<div class="avatar-radial-blur"></div>
<!-- Glow Rings -->
<div class="absolute inset-0 rounded-full border-4 border-secondary/60 animate-pulse scale-150 blur-md"></div>
<div class="absolute inset-0 rounded-full border-2 border-secondary/80 animate-pulse scale-125 blur-sm"></div>
<div class="absolute inset-0 rounded-full border border-secondary shadow-[0_0_60px_rgba(104,252,191,0.8)] z-10"></div>
<img alt="Friend Avatar" class="w-full h-full object-cover rounded-full relative z-20 border-2 border-primary/20" data-alt="Close-up portrait of a young man with a slight smile, dramatic cyan and purple studio lighting, high fashion photography style" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDy3AMRy7x2yLVPtHxiBJIGdMpWT7Xj4gV6uLhb_oOPzqWWR0JYPaRuKLCxMQ261HejonJz6ZsjiG-9jaV88RKO_sy_qXzIn_3xzvs7YDu4um8fHfvjFZ48-lIOfCn4z4DO-N14M7eOqoSRWYXsdqYiyNzsY0EO6UUpcwo0asf6v3TZuHepLOolaHqSRPL3hW5Eo1bf0lt1mXHiHE22CmK53JIIY6nMJ6Jvjd-FgNsRJ0zTGprNOjlFoPyZ8XiV1sN5Az8TVOMHsXA"/>
<!-- Online Status Pulse -->
<div class="absolute bottom-1 right-1 w-6 h-6 bg-secondary rounded-full border-2 border-surface shadow-[0_0_15px_#68fcbf] z-30"></div>
</div>
<!-- Status Metadata -->
<div class="text-center relative z-20">
<span class="label-sm uppercase tracking-[0.2em] text-secondary font-bold mb-1 block drop-shadow-[0_0_8px_rgba(104,252,191,0.5)]">DESTINATION REACHED IN</span>
<div class="font-headline text-5xl font-extrabold tracking-tight text-on-surface drop-shadow-[0_0_15px_rgba(255,255,255,0.3)]">4.2s</div>
</div>
</div>
</div>
<!-- Central Glassmorphic Status Card -->
<div class="absolute bottom-32 z-50 w-full max-w-md px-6">
<div class="bg-surface-variant/40 backdrop-blur-2xl rounded-xl p-8 border border-outline-variant/20 shadow-[0_20px_50px_rgba(0,0,0,0.6)]">
<div class="flex items-center justify-between mb-6">
<div class="flex items-center gap-3">
<div class="w-2.5 h-2.5 bg-secondary rounded-full shadow-[0_0_12px_#68fcbf] animate-pulse"></div>
<h2 class="font-headline font-bold text-lg tracking-wide uppercase">SYNCING QUANTUM FIELD</h2>
</div>
<span class="font-label text-secondary text-sm font-bold animate-glitch">88%</span>
</div>
<!-- Progress Bar with Intense Glow -->
<div class="relative h-2.5 w-full bg-surface-container-lowest rounded-full overflow-hidden shadow-[inset_0_1px_4px_rgba(0,0,0,0.5)]">
<div class="absolute left-0 top-0 h-full w-[88%] bg-gradient-to-r from-primary via-secondary to-white shadow-[0_0_20px_rgba(104,252,191,0.8)] transition-all duration-300"></div>
<!-- Particle Overlay effect for bar -->
<div class="absolute inset-0 opacity-40 bg-[radial-gradient(circle,white_1px,transparent_1px)] bg-[size:8px_8px]"></div>
</div>
<div class="mt-6 flex justify-between items-center text-on-surface-variant">
<div class="flex flex-col">
<span class="text-[10px] uppercase tracking-widest font-bold opacity-60">TARGET_ID</span>
<span class="font-label text-sm text-primary animate-glitch" style="animation-duration: 1.5s;">KAIROS_V09</span>
</div>
<div class="flex flex-col text-right">
<span class="text-[10px] uppercase tracking-widest font-bold opacity-60">BITRATE</span>
<span class="font-label text-sm text-secondary animate-glitch" style="animation-duration: 2s;">4.5 GBPS</span>
</div>
</div>
</div>
</div>
<!-- Floating UI Elements with Glitch Effects -->
<div class="absolute top-24 right-8 flex flex-col gap-4 opacity-80 z-40">
<div class="bg-surface-container-high/60 backdrop-blur-md px-4 py-2 rounded-lg border border-outline-variant/20 text-[10px] font-bold tracking-tighter animate-glitch shadow-[0_0_15px_rgba(0,0,0,0.3)]">
                LATENCY: <span class="text-secondary">12ms</span>
</div>
<div class="bg-surface-container-high/60 backdrop-blur-md px-4 py-2 rounded-lg border border-outline-variant/20 text-[10px] font-bold tracking-tighter animate-glitch shadow-[0_0_15px_rgba(0,0,0,0.3)]" style="animation-delay: 0.5s;">
                COORDS: <span class="text-primary">40.7128° N, 74.0060° W</span>
</div>
</div>
</main>
<!-- Bottom Navigation Shell (Hidden as per original) -->
<nav class="hidden md:hidden fixed bottom-0 left-0 w-full z-50 flex justify-around items-center px-4 pb-6 pt-2 bg-[#150629]/60 backdrop-blur-lg no-border border-t-[1px] border-violet-500/15 shadow-[0_-10px_40px_rgba(167,139,250,0.12)] rounded-t-xl">
<div class="flex flex-col items-center justify-center text-violet-400/50 p-2 font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">
<span class="material-symbols-outlined mb-1">explore</span>
            NEXUS
        </div>
<div class="flex flex-col items-center justify-center bg-violet-500/20 text-emerald-400 rounded-xl p-2 drop-shadow-[0_0_8px_rgba(104,252,191,0.5)] font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">
<span class="material-symbols-outlined mb-1">travel_explore</span>
            MAP
        </div>
<div class="flex flex-col items-center justify-center text-violet-400/50 p-2 font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">
<span class="material-symbols-outlined mb-1">group</span>
            SQUAD
        </div>
<div class="flex flex-col items-center justify-center text-violet-400/50 p-2 font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">
<span class="material-symbols-outlined mb-1">sensors</span>
            INTEL
        </div>
</nav>
</body></html>

<!-- Main Map Overview -->
<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&amp;family=Manrope:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            "colors": {
                    "tertiary-container": "#8342f4",
                    "primary-container": "#ab8ffe",
                    "on-primary-container": "#290070",
                    "surface-container-lowest": "#000000",
                    "surface-container-highest": "#301a4d",
                    "on-secondary": "#005e40",
                    "surface-tint": "#b79fff",
                    "surface-variant": "#301a4d",
                    "on-secondary-fixed": "#004931",
                    "inverse-surface": "#fff7ff",
                    "secondary-dim": "#57edb1",
                    "outline-variant": "#514166",
                    "error-dim": "#d73357",
                    "on-primary-fixed": "#000000",
                    "on-tertiary": "#2b0065",
                    "surface-container": "#22103a",
                    "secondary-fixed": "#68fcbf",
                    "surface-container-high": "#291543",
                    "surface": "#150629",
                    "tertiary-fixed": "#c0a0ff",
                    "on-secondary-fixed-variant": "#006948",
                    "primary-fixed": "#ab8ffe",
                    "tertiary-fixed-dim": "#b48fff",
                    "primary-dim": "#a88cfb",
                    "on-surface": "#efdfff",
                    "on-error-container": "#ffb2b9",
                    "on-surface-variant": "#b7a3cf",
                    "error": "#ff6e84",
                    "inverse-primary": "#684cb6",
                    "secondary-container": "#006c4b",
                    "on-error": "#490013",
                    "primary": "#b79fff",
                    "on-tertiary-container": "#ffffff",
                    "primary-fixed-dim": "#9d81f0",
                    "outline": "#806e96",
                    "tertiary-dim": "#8a4cfc",
                    "inverse-on-surface": "#5e4e74",
                    "surface-container-low": "#1b0a31",
                    "surface-bright": "#372056",
                    "on-secondary-container": "#e0ffec",
                    "secondary-fixed-dim": "#57edb1",
                    "on-primary": "#361083",
                    "tertiary": "#af88ff",
                    "on-tertiary-fixed-variant": "#4a00a4",
                    "background": "#150629",
                    "on-primary-fixed-variant": "#330b80",
                    "surface-dim": "#150629",
                    "secondary": "#68fcbf",
                    "on-tertiary-fixed": "#200051",
                    "on-background": "#efdfff",
                    "error-container": "#a70138"
            },
            "borderRadius": {
                    "DEFAULT": "0.125rem",
                    "lg": "0.25rem",
                    "xl": "0.5rem",
                    "full": "0.75rem"
            },
            "fontFamily": {
                    "headline": ["Space Grotesk"],
                    "body": ["Manrope"],
                    "label": ["Space Grotesk"]
            }
          },
        },
      }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .glass-panel {
            background: rgba(48, 26, 77, 0.4);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .neon-glow-primary {
            box-shadow: 0 0 20px rgba(183, 159, 255, 0.15);
        }
        .neon-glow-secondary {
            filter: drop-shadow(0 0 8px rgba(104, 252, 191, 0.5));
        }
        .isometric-map {
            transform: rotateX(45deg) rotateZ(-30deg);
            transform-style: preserve-3d;
        }
    </style>
<style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head>
<body class="bg-surface text-on-surface font-body overflow-hidden h-screen w-screen selection:bg-secondary selection:text-on-secondary">
<!-- Header / TopAppBar -->
<header class="fixed top-0 w-full z-[100] flex items-center justify-between px-6 h-16 bg-[#150629]/40 backdrop-blur-xl no-border bg-gradient-to-b from-[#150629] to-transparent shadow-[0_0_20px_rgba(167,139,250,0.1)]">
<div class="flex items-center gap-4">
<button class="active:scale-95 transition-transform text-violet-400">
<span class="material-symbols-outlined" data-icon="arrow_back">arrow_back</span>
</button>
<h1 class="text-violet-300 font-['Space_Grotesk'] tracking-tighter font-bold uppercase">TELEPORTING...</h1>
</div>
<div class="flex items-center gap-6">
<div class="text-xl font-bold tracking-widest text-violet-300 uppercase">MAP</div>
<button class="hover:text-emerald-400 transition-colors duration-300 text-violet-500">
<span class="material-symbols-outlined" data-icon="search">search</span>
</button>
</div>
</header>
<!-- Main Canvas: Isometric Map View -->
<main class="relative w-full h-full flex items-center justify-center bg-surface overflow-hidden">
<!-- Deep Space Background Gradients -->
<div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,_#22103a_0%,_#150629_100%)]"></div>
<div class="absolute -top-1/4 -right-1/4 w-[600px] h-[600px] bg-primary/10 rounded-full blur-[120px]"></div>
<div class="absolute -bottom-1/4 -left-1/4 w-[600px] h-[600px] bg-secondary/10 rounded-full blur-[120px]"></div>
<!-- 3D Map Container -->
<div class="relative w-full max-w-5xl aspect-video isometric-map">
<!-- Floorplan Image -->
<div class="absolute inset-0 bg-surface-container-high rounded-xl outline outline-primary/20 p-1">
<img alt="festival map" class="w-full h-full object-cover rounded-lg opacity-40 grayscale sepia brightness-50" data-alt="Top-down isometric view of a futuristic 3D cyberpunk festival floorplan with neon pathways, stage structures, and glowing holographic area markers" src="https://lh3.googleusercontent.com/aida-public/AB6AXuCpiv7BnF9W3hpAqycehPhQ0gRbsXSn3ddHymdVT3qx6IeE-B5cOZ8xai2T96kDd_peLGJ3beWVgP4yBleq_wiuKVvF_Cl49KouTZzeLUGIa7FQ36ReRbJ1Hy3ylhaQgnUknjGIknziVuGErzeRfPheqngK2SoMrtFjcwnEqO004WiMbzTqvzrnhdjCnNTKEO-4IkDNyfXfHpPhyA5sX9S7az8gl8h9bO3XAbd7bHxQuat6CRzzu_hqHb2Bj0NPtrmLp56D_G8L4Nk"/>
<!-- Map Grid Overlay -->
<div class="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10 mix-blend-overlay"></div>
</div>
<!-- Friend Markers (Floaters) -->
<!-- Marker 1 -->
<div class="absolute top-[20%] left-[30%] transform translate-z-12 flex flex-col items-center">
<div class="relative group">
<div class="absolute -inset-1 bg-secondary rounded-full blur-sm animate-pulse opacity-50"></div>
<img class="w-10 h-10 rounded-full border-2 border-secondary relative z-10" data-alt="Avatar of a young man with neon lighting reflections on his face, looking into camera" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBlmdJrUYE1VvVb--HU5H1APcTULzBqvJzYnulkIxq1d0P8v_WSVIt_Y-DSD-ZjJW6GikNgX82Wl1a0WPfvnIqq54KYC9C-APBwd6InNsGiSGubmF6n-U6Gyu_LXrE9sv79cC7Zf5n6qzxatB_TeqgEFWf0yaZBtVG69phCktG_83gI_F44rHxys8SzvZZx4LZCJCdkYvbu5cF4PV2e_4LqtD4GXrVNPZry-osgY6Ph2KIsQh6D3Xle3cjK3-xBZjHAZGG1LYKJ14M"/>
<div class="absolute -bottom-1 -right-1 w-3 h-3 bg-secondary rounded-full border-2 border-surface z-20"></div>
</div>
<span class="mt-2 text-[10px] font-headline bg-surface/80 backdrop-blur-md px-2 py-0.5 rounded-full text-secondary-fixed border border-secondary/30">MAX @ MAIN_STAGE</span>
</div>
<!-- Marker 2 -->
<div class="absolute top-[60%] left-[70%] transform translate-z-12 flex flex-col items-center">
<div class="relative group">
<div class="absolute -inset-1 bg-primary rounded-full blur-sm animate-pulse opacity-50"></div>
<img class="w-10 h-10 rounded-full border-2 border-primary relative z-10" data-alt="Avatar of a smiling woman with futuristic makeup and blue ambient lighting" src="https://lh3.googleusercontent.com/aida-public/AB6AXuCcXj-6yCM5rkn7VV9lKxyWDqq6KNmHciIASmAHY_Mi3roOP4bn_nUFkvU5U7GC9lMZTh7_sVO6lLwJshdc5pHYwfMHO_WSG-plajQc9hCrHQ9s9sk8Jj8JqM62s1OuJp2hEIheP1nQyrJHSeJi36RwH0gnG5_zDNh2Gt4eV1bdWK27yxHeklIaKhJ5w-pTLhhE2a_1HR5yk06OmkJm0LzVlwi7USY9DONfZ2sna78ewCzpz7bphTLBkPzQuKrbMwxyceyD7TZVvzA"/>
<div class="absolute -bottom-1 -right-1 w-3 h-3 bg-primary rounded-full border-2 border-surface z-20"></div>
</div>
<span class="mt-2 text-[10px] font-headline bg-surface/80 backdrop-blur-md px-2 py-0.5 rounded-full text-primary border border-primary/30">ELARA @ NEBULA_LOUNGE</span>
</div>
<!-- Marker 3 -->
<div class="absolute top-[40%] left-[50%] transform translate-z-12 flex flex-col items-center">
<div class="relative group">
<div class="absolute -inset-2 bg-secondary rounded-full blur-md opacity-20"></div>
<div class="w-12 h-12 bg-surface-container-highest rounded-full border-2 border-secondary flex items-center justify-center relative z-10">
<span class="material-symbols-outlined text-secondary" data-icon="location_on">location_on</span>
</div>
</div>
<span class="mt-2 text-[10px] font-headline bg-secondary/20 backdrop-blur-md px-2 py-0.5 rounded-full text-secondary-fixed border border-secondary/50">VIP RECEPTION</span>
</div>
</div>
<!-- Floating UI Overlays -->
<!-- Passe Digital Card (Holographic) -->
<div class="fixed top-20 left-1/2 -translate-x-1/2 w-[85%] max-w-sm glass-panel rounded-xl border border-primary/20 p-4 shadow-2xl z-50">
<div class="flex items-center justify-between mb-4">
<div class="flex items-center gap-2">
<div class="w-2 h-2 bg-secondary rounded-full animate-pulse"></div>
<span class="font-headline text-[10px] tracking-widest text-on-surface-variant uppercase">Passe Digital Interativo</span>
</div>
<span class="font-headline text-primary font-bold">X-9982</span>
</div>
<div class="flex items-end justify-between">
<div>
<h2 class="font-headline text-xl font-extrabold text-on-surface tracking-tight uppercase leading-tight">NEBULA<br/>FESTIVAL '24</h2>
<p class="text-[10px] font-label text-secondary mt-1 tracking-widest uppercase">ACCESS GRANTED: ALL ZONES</p>
</div>
<div class="w-12 h-12 bg-white rounded-lg p-1">
<img alt="qr code" class="w-full h-full grayscale" data-alt="Futuristic glowing QR code on a glass surface with microchip patterns" src="https://lh3.googleusercontent.com/aida-public/AB6AXuDT79kdVdg31vXyOJ-lZ9ETVCA9EQobGlLziFgwgqQnvpLE6WG9EHaNLEUfA6__8-ptrCmQf8yR6fL-tK99oDHHFxdgkVnrlj0M86xFBBj7ptCPskBFcOLGXK4juAAkRo1_4ye131_jcqJ8yPveFMtYw_-Lx2SEbEhs0EqnCOp5ScAhI-IwncJw_nyUjODG8ab6ySYQeYBl3lp4Sz6jO4_ekfKzHk8aB3aYaQzXEeZ8hzyHhPggpTfLmq8ywi-bgKz2cUTTymwRJ5k"/>
</div>
</div>
</div>
<!-- Right Side HUD Controls -->
<div class="fixed right-6 top-1/2 -translate-y-1/2 flex flex-col gap-4 z-50">
<button class="w-12 h-12 glass-panel rounded-full border border-primary/20 flex items-center justify-center hover:bg-primary/20 transition-all text-primary">
<span class="material-symbols-outlined" data-icon="layers">layers</span>
</button>
<button class="w-12 h-12 glass-panel rounded-full border border-primary/20 flex items-center justify-center hover:bg-primary/20 transition-all text-primary">
<span class="material-symbols-outlined" data-icon="my_location">my_location</span>
</button>
<div class="h-12 glass-panel rounded-full border border-primary/20 flex flex-col items-center justify-center p-1 gap-1">
<button class="text-primary/60 hover:text-primary"><span class="material-symbols-outlined" data-icon="add">add</span></button>
<div class="w-full h-[1px] bg-primary/20"></div>
<button class="text-primary/60 hover:text-primary"><span class="material-symbols-outlined" data-icon="remove">remove</span></button>
</div>
</div>
<!-- AI Concierge Dock (Floating at bottom, above nav) -->
<div class="fixed bottom-24 left-1/2 -translate-x-1/2 w-[90%] max-w-xl z-50">
<div class="glass-panel border border-secondary/30 rounded-2xl p-4 flex items-center gap-4 shadow-[0_0_40px_rgba(104,252,191,0.15)] group">
<div class="relative">
<div class="absolute -inset-1 bg-secondary rounded-full blur-md opacity-20 group-hover:opacity-40 transition-opacity"></div>
<div class="w-12 h-12 bg-surface-container rounded-full flex items-center justify-center border border-secondary/50">
<span class="material-symbols-outlined text-secondary" data-icon="smart_toy" data-weight="fill" style="font-variation-settings: 'FILL' 1;">smart_toy</span>
</div>
</div>
<div class="flex-1">
<p class="text-[10px] font-headline text-secondary-fixed uppercase tracking-wider mb-0.5">Concierge Ativo</p>
<p class="text-sm font-body text-on-surface-variant italic">"A apresentação do DJ Kinesis começa em 15min no Palco Nebula. Deseja a rota mais rápida?"</p>
</div>
<button class="bg-secondary/10 hover:bg-secondary/20 p-2 rounded-xl border border-secondary/20 text-secondary transition-colors">
<span class="material-symbols-outlined" data-icon="send">send</span>
</button>
</div>
</div>
</main>
<!-- BottomNavBar -->
<nav class="fixed bottom-0 left-0 w-full z-50 flex justify-around items-center px-4 pb-6 pt-2 bg-[#150629]/60 backdrop-blur-lg rounded-t-xl no-border border-t-[1px] border-violet-500/15 shadow-[0_-10px_40px_rgba(167,139,250,0.12)]">
<!-- NEXUS -->
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined" data-icon="explore">explore</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold mt-1">NEXUS</span>
</a>
<!-- MAP (Active) -->
<a class="flex flex-col items-center justify-center bg-violet-500/20 text-emerald-400 rounded-xl p-2 drop-shadow-[0_0_8px_rgba(104,252,191,0.5)] active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined" data-icon="travel_explore">travel_explore</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold mt-1">MAP</span>
</a>
<!-- SQUAD -->
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined" data-icon="group">group</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold mt-1">SQUAD</span>
</a>
<!-- INTEL -->
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined" data-icon="sensors">sensors</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold mt-1">INTEL</span>
</a>
</nav>
<!-- Map Legend / Stats (Asymmetric Layout Element) -->
<div class="fixed left-6 bottom-32 z-40 hidden md:block">
<div class="glass-panel border-l-4 border-secondary p-4 flex flex-col gap-3 max-w-[200px]">
<div>
<p class="text-[10px] font-headline text-secondary uppercase tracking-widest">Active Peers</p>
<p class="text-2xl font-headline font-bold text-on-surface">1.2k</p>
</div>
<div class="flex flex-col gap-1">
<div class="flex items-center justify-between text-[10px] font-label text-on-surface-variant">
<span>STAGE LOAD</span>
<span>88%</span>
</div>
<div class="w-full h-1 bg-surface-container rounded-full overflow-hidden">
<div class="h-full bg-secondary w-[88%]"></div>
</div>
</div>
</div>
</div>
</body></html>

<!-- Main Stage Zoom Re-entry -->
<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&amp;family=Manrope:wght@300;400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            "colors": {
                    "on-tertiary-fixed-variant": "#4a00a4",
                    "tertiary": "#af88ff",
                    "surface-container-high": "#291543",
                    "surface-tint": "#b79fff",
                    "surface-variant": "#301a4d",
                    "inverse-on-surface": "#5e4e74",
                    "error-dim": "#d73357",
                    "error": "#ff6e84",
                    "surface-container-lowest": "#000000",
                    "secondary-dim": "#57edb1",
                    "on-surface": "#efdfff",
                    "primary-dim": "#a88cfb",
                    "primary-container": "#ab8ffe",
                    "surface-container-highest": "#301a4d",
                    "on-error-container": "#ffb2b9",
                    "tertiary-container": "#8342f4",
                    "on-tertiary-container": "#ffffff",
                    "on-surface-variant": "#b7a3cf",
                    "on-background": "#efdfff",
                    "surface": "#150629",
                    "primary": "#b79fff",
                    "on-primary": "#361083",
                    "on-primary-fixed": "#000000",
                    "on-secondary-fixed-variant": "#006948",
                    "secondary-fixed": "#68fcbf",
                    "tertiary-fixed": "#c0a0ff",
                    "on-tertiary-fixed": "#200051",
                    "outline": "#806e96",
                    "error-container": "#a70138",
                    "outline-variant": "#514166",
                    "primary-fixed-dim": "#9d81f0",
                    "inverse-surface": "#fff7ff",
                    "secondary-container": "#006c4b",
                    "background": "#150629",
                    "on-error": "#490013",
                    "primary-fixed": "#ab8ffe",
                    "tertiary-fixed-dim": "#b48fff",
                    "secondary-fixed-dim": "#57edb1",
                    "on-secondary-fixed": "#004931",
                    "inverse-primary": "#684cb6",
                    "tertiary-dim": "#8a4cfc",
                    "secondary": "#68fcbf",
                    "on-primary-container": "#290070",
                    "on-secondary-container": "#e0ffec",
                    "on-primary-fixed-variant": "#330b80",
                    "surface-bright": "#372056",
                    "surface-dim": "#150629",
                    "on-tertiary": "#2b0065",
                    "on-secondary": "#005e40",
                    "surface-container-low": "#1b0a31",
                    "surface-container": "#22103a"
            },
            "borderRadius": {
                    "DEFAULT": "0.125rem",
                    "lg": "0.25rem",
                    "xl": "0.5rem",
                    "full": "0.75rem"
            },
            "fontFamily": {
                    "headline": ["Space Grotesk"],
                    "body": ["Manrope"],
                    "label": ["Space Grotesk"]
            }
          },
        },
      }
    </script>
<style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .glass-panel {
            background: rgba(48, 26, 77, 0.4);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .map-mesh {
            background-image: radial-gradient(circle at 2px 2px, rgba(183, 159, 255, 0.05) 1px, transparent 0);
            background-size: 40px 40px;
        }
        .neon-glow-primary {
            filter: drop-shadow(0 0 8px rgba(183, 159, 255, 0.4));
        }
        .neon-glow-secondary {
            filter: drop-shadow(0 0 8px rgba(104, 252, 191, 0.5));
        }
        .isometric-view {
            transform: perspective(1000px) rotateX(45deg) rotateZ(-15deg);
        }
    </style>
<style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head>
<body class="bg-background text-on-background font-body overflow-hidden selection:bg-secondary/30">
<!-- Top Navigation Shell -->
<header class="fixed top-0 w-full bg-[#150629]/40 backdrop-blur-xl z-[100] flex items-center justify-between px-6 h-16 shadow-[0_0_20px_rgba(167,139,250,0.1)] bg-gradient-to-b from-[#150629] to-transparent">
<div class="flex items-center gap-4">
<button class="text-violet-400 active:scale-95 transition-transform">
<span class="material-symbols-outlined">arrow_back</span>
</button>
<h1 class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-400">TELEPORTING...</h1>
</div>
<div class="flex items-center gap-6">
<div class="hidden md:flex gap-8">
<span class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-500 hover:text-emerald-400 transition-colors duration-300 cursor-pointer">NEXUS</span>
<span class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-300 hover:text-emerald-400 transition-colors duration-300 cursor-pointer">MAP</span>
<span class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-500 hover:text-emerald-400 transition-colors duration-300 cursor-pointer">SQUAD</span>
<span class="font-['Space_Grotesk'] tracking-tighter font-bold uppercase text-violet-500 hover:text-emerald-400 transition-colors duration-300 cursor-pointer">INTEL</span>
</div>
<div class="text-xl font-bold tracking-widest text-violet-300 font-headline">AETHER</div>
</div>
</header>
<!-- Main Content: Isometric Map Canvas -->
<main class="relative h-screen w-full flex items-center justify-center bg-surface overflow-hidden">
<!-- Map Background with Bokeh -->
<div class="absolute inset-0 z-0 overflow-hidden">
<div class="absolute inset-0 map-mesh opacity-20"></div>
<img alt="Cinematic bokeh blur of a music festival arena with distant neon lights and hazy atmosphere" class="w-full h-full object-cover blur-2xl opacity-40 scale-110" data-alt="Cinematic bokeh blur of a music festival arena at night with distant neon purple and teal lights in a hazy atmosphere" src="https://lh3.googleusercontent.com/aida-public/AB6AXuCoDsDsxmJi4j9OYa_eT3fuV2daS96pTuVffT1KuSrGatc6XxMAWaUo5skEsTQC8JMCgbcrS24M5OEbvZ7QIR9c5dPQRzeOBckV82HpW91_XDNz6rtkWV8TZM4nwzpHISUTPIKlKO_X_tBHbCfQtaYD6ShWaDhfHYR7em0pd9fq-AceEfqo34b1YE7MSU9VWrvA_elueAyK6V82GLGsuNwvsMKB6cZSHkMTvb3FM-UHC6Iuaap_TJdJVP-38A74aS2AQZcfgcYfgSs"/>
</div>
<!-- Isometric POI Stage Container -->
<div class="relative z-10 w-full max-w-6xl px-8 flex flex-col md:flex-row items-center justify-between gap-12">
<!-- Map View Area -->
<div class="relative flex-1 group">
<!-- Map Controls Overlay -->
<div class="absolute -top-12 left-0 z-20 flex gap-3">
<button class="glass-panel px-4 py-2 rounded-xl text-primary font-headline text-xs tracking-widest border border-outline-variant/20 hover:border-primary transition-all">
                        ISO_VIEW: 2.4X
                    </button>
<button class="glass-panel px-4 py-2 rounded-xl text-secondary font-headline text-xs tracking-widest border border-outline-variant/20">
                        LIVE_FEED: ACTIVE
                    </button>
</div>
<!-- Isometric Stage Mockup -->
<div class="relative w-full aspect-square md:aspect-video flex items-center justify-center">
<div class="isometric-view relative w-80 h-80 bg-surface-container-high/40 rounded-xl border border-primary/20 shadow-[0_0_100px_rgba(183,159,255,0.1)]">
<!-- Holographic Stage Body -->
<div class="absolute inset-0 bg-gradient-to-tr from-primary/10 to-transparent"></div>
<!-- Main Stage POI Anchor -->
<div class="absolute bottom-1/2 left-1/2 -translate-x-1/2 translate-y-1/2 flex flex-col items-center">
<!-- Floating Label -->
<div class="mb-4 glass-panel px-4 py-2 rounded-lg border border-primary neon-glow-primary">
<span class="font-headline font-bold text-primary tracking-widest text-sm">MAIN STAGE</span>
</div>
<!-- Isometric Stage Structure -->
<div class="w-48 h-32 bg-primary/20 rounded-lg relative overflow-hidden border-t-2 border-primary">
<img alt="Isometric view of a massive futuristic concert stage with large LED screens and vertical light beams" class="w-full h-full object-cover opacity-60" data-alt="Detailed isometric view of a futuristic concert stage with massive vertical LED screens showing cyan waveforms and violet light beams" src="https://lh3.googleusercontent.com/aida-public/AB6AXuCmGYIE0-WXMYvOZXapFZAXyaEFm04U5SOf9b1maGGa95lWsM8IzUlXDLdNtUNoC_R3mrN2_C1wjY3G3hScRkQm6qaUsVV_miNjqZ58CrKosQHr8AbUUrEPkXgbBWlmmu0VxWLH1GD7ibZ0YxRgYDBgDoZgPm-qzsdfRCRlgMFyQf7QwENZytt-P4Jxk_Ncl_oXf5syipU6-tQ_QzH7-pwLrE8QtjMRzxdJiKrN37PZVE7QzCJkG0YO_61HbQ4INIgizpuhi2E1JIw"/>
<div class="absolute inset-0 bg-gradient-to-t from-background via-transparent to-transparent"></div>
<!-- Pulse Rings -->
<div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-32 h-32 rounded-full border border-secondary opacity-30 animate-pulse"></div>
</div>
</div>
<!-- Secondary POIs around -->
<div class="absolute top-10 right-10 w-4 h-4 rounded-full bg-secondary neon-glow-secondary"></div>
<div class="absolute bottom-20 left-10 w-3 h-3 rounded-full bg-primary-container opacity-50"></div>
</div>
<!-- Overlay UI Elements (Floating relative to screen) -->
<div class="absolute bottom-4 left-4 flex flex-col gap-4">
<!-- Live Stream Preview Card -->
<div class="glass-panel p-4 rounded-xl border border-outline-variant/15 w-64 shadow-xl">
<div class="relative rounded-lg overflow-hidden mb-3 aspect-video group/video">
<img alt="Live stream video frame showing a DJ performing in front of a massive crowd with laser lights" class="w-full h-full object-cover" data-alt="Live stream video preview showing a DJ performing at a massive music festival with teal and purple laser lights" src="https://lh3.googleusercontent.com/aida-public/AB6AXuAA-XaQ5B98vPdrIL8AMGADBDUFqpzzlcMwYDSkn4cK0c95bgP9556x7ZWgAcpo22KB81sVO5xbGCHQqVC1b7h8QhAGDRvhBqUHUmg9yXKa6-KFBfbYKa5iHu-5ylO80_BrBx5P5EMtuyFA5z2XygbiPeBxtw7rQ3fQRwlyYdVac76QXrEWUgtW1vL8rzo7kxn4HCNFWoJd9xgaoJXB1Vhe36O384t7VFh18KkXfnris8iSdziFBmHaCdjj81FXt7q7cUZtt5zBetw"/>
<div class="absolute inset-0 flex items-center justify-center bg-surface/40">
<button class="w-10 h-10 rounded-full bg-primary text-on-primary flex items-center justify-center active:scale-90 transition-transform">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">play_arrow</span>
</button>
</div>
<div class="absolute top-2 left-2 px-2 py-0.5 bg-error text-[10px] font-headline font-bold rounded">LIVE</div>
</div>
<div class="flex justify-between items-center">
<span class="font-headline font-bold text-xs tracking-tighter text-on-surface">LIVE STREAM PREVIEW</span>
<span class="text-[10px] font-label text-on-surface-variant">14.2K VIEWERS</span>
</div>
</div>
<!-- Share Location Button -->
<button class="bg-gradient-to-r from-primary to-primary-container px-6 py-3 rounded-xl flex items-center justify-center gap-3 shadow-[0_0_20px_rgba(183,159,255,0.3)] hover:scale-105 transition-transform active:scale-95">
<span class="material-symbols-outlined text-on-primary">share_location</span>
<span class="text-on-primary font-headline font-bold text-sm tracking-widest uppercase">SHARE LOCATION</span>
</button>
</div>
</div>
</div>
<!-- Friend Activity Feed (Right Side) -->
<aside class="w-full md:w-80 glass-panel rounded-xl border border-outline-variant/15 p-6 h-fit max-h-[618px] flex flex-col overflow-hidden">
<div class="flex items-center justify-between mb-8">
<h2 class="font-headline font-bold text-lg tracking-tighter text-primary">SQUAD ACTIVITY</h2>
<span class="material-symbols-outlined text-on-surface-variant">group</span>
</div>
<div class="space-y-6 overflow-y-auto pr-2 custom-scrollbar">
<!-- Friend 1 -->
<div class="flex items-start gap-4">
<div class="relative shrink-0">
<div class="w-12 h-12 rounded-full border-2 border-secondary p-0.5">
<img alt="Profile of a young woman with neon face paint" class="w-full h-full rounded-full object-cover" data-alt="Profile portrait of a young woman with neon face paint in a festival setting with purple lights" src="https://lh3.googleusercontent.com/aida-public/AB6AXuAu0Q85VmJ_Er2-eMc12e7eJi1k1CepRvkK6WDSzAmFdmKDasiXbMbjCC20qgQYph1nSqIucwDrD38kstIn73gHbzHZLrGjbqywQHsooPl52zL8kzR31K_T2n28MOc2_02LBhdvV06L3L8Il7Ir_P0ubp-7d9VRoT4dJ9G3TuYdwZBStwFbzbphTjt1Tb0nvBkR6f3OsDyCKLYVaIzr9A0SpnQdBwQRkRu2_jMg6PYGKk5T65WZUCJZ8EGUvuPN77h136VKKTKno6I"/>
</div>
<div class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-secondary border-2 border-surface flex items-center justify-center">
<span class="material-symbols-outlined text-[10px] text-on-secondary" style="font-variation-settings: 'FILL' 1;">music_note</span>
</div>
</div>
<div class="flex-1">
<h3 class="font-headline font-bold text-sm text-on-surface">ZARA_FLUX</h3>
<p class="text-xs text-secondary-dim font-medium italic mt-1">Vibing at Main Stage</p>
<span class="text-[10px] font-label text-on-surface-variant uppercase tracking-widest mt-1 block">DISTANCE: 42M</span>
</div>
</div>
<!-- Friend 2 -->
<div class="flex items-start gap-4">
<div class="relative shrink-0">
<div class="w-12 h-12 rounded-full border-2 border-primary p-0.5">
<img alt="Profile of a young man with futuristic sunglasses" class="w-full h-full rounded-full object-cover" data-alt="Profile of a man wearing futuristic reflective sunglasses with neon pink city reflections" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBAA3zUfvPKF7HWnfz5MCngzVKctYBgHGIHQKfPKdBOpe5U38JENVUtfh6o6t3ynUkEWRzxKmXvmzeQdkzjVoGBUQsuJXe1J-h78oX23vHlhbcF38XXpwlGPTJXfQz-5IUjp17GLhjxpNmMjS203SmVPmqG90HMh7QMXCJmhI2ZIlVIcYdhaA9YE1YDSCx7KY_Ll63VY3p-EnKVvayZFU2xsHsw1z5b9fi8gKytTDFhEFO6iSUklonM1bDGVr_gSBmWeQRHL-C8k-I"/>
</div>
<div class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-primary border-2 border-surface"></div>
</div>
<div class="flex-1">
<h3 class="font-headline font-bold text-sm text-on-surface">NEON_DRIFT</h3>
<p class="text-xs text-on-surface-variant font-medium mt-1">Heading to Oasis Bar</p>
<span class="text-[10px] font-label text-on-surface-variant uppercase tracking-widest mt-1 block">DISTANCE: 210M</span>
</div>
</div>
<!-- Friend 3 -->
<div class="flex items-start gap-4 opacity-60">
<div class="relative shrink-0">
<div class="w-12 h-12 rounded-full border-2 border-outline-variant p-0.5 grayscale">
<img alt="Profile of a smiling woman in soft lighting" class="w-full h-full rounded-full object-cover" data-alt="Profile portrait of a woman in low light festival atmosphere with soft bokeh background" src="https://lh3.googleusercontent.com/aida-public/AB6AXuCKGUZyOwVZMVThzh-_ISOGMTijwQ4CseWHA8y1fpkJ7eVlzEwki8TNjdFdWZYuL9V0ppO7grEzt9-arc_5dY1Zk0no1Ywkoqm8sTUwLvfcwUr5CvKHQFXC44LFkabRBa-5WhqtnorS8Y1Gye56utpfAbyEK9Nrm6qxVjHEIu7bYx0eBJwHk77f4ooRKqT1DmgFwTsvb0xDONDRxhdAfeS4InGBZgYhlfc2hH07ImMEJnsFpp0Gk1lfutQ4c-MZhckAhQwfgwmUXGw"/>
</div>
</div>
<div class="flex-1">
<h3 class="font-headline font-bold text-sm text-on-surface">CYBER_KATE</h3>
<p class="text-xs text-on-surface-variant font-medium mt-1">Last seen 15m ago</p>
<span class="text-[10px] font-label text-on-surface-variant uppercase tracking-widest mt-1 block">OFFLINE</span>
</div>
</div>
</div>
<button class="mt-8 w-full py-3 text-xs font-headline font-bold text-primary tracking-widest border border-primary/30 rounded-lg hover:bg-primary/10 transition-colors uppercase">
                    INVITE SQUAD
                </button>
</aside>
</div>
</main>
<!-- Bottom Navigation Shell -->
<nav class="fixed bottom-0 left-0 w-full z-50 flex justify-around items-center px-4 pb-6 pt-2 bg-[#150629]/60 backdrop-blur-lg rounded-t-xl no-border border-t-[1px] border-violet-500/15 shadow-[0_-10px_40px_rgba(167,139,250,0.12)]">
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined mb-1">explore</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">NEXUS</span>
</a>
<a class="flex flex-col items-center justify-center bg-violet-500/20 text-emerald-400 rounded-xl p-2 drop-shadow-[0_0_8px_rgba(104,252,191,0.5)] active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined mb-1">travel_explore</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">MAP</span>
</a>
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined mb-1">group</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">SQUAD</span>
</a>
<a class="flex flex-col items-center justify-center text-violet-400/50 p-2 hover:bg-violet-500/10 hover:text-violet-200 active:scale-90 transition-all duration-200 ease-out" href="#">
<span class="material-symbols-outlined mb-1">sensors</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-[0.1em] font-bold">INTEL</span>
</a>
</nav>
<!-- FAB Suppression: On Detail/Map view screens, typically suppressed per instructions unless core action -->
</body></html>

<!-- Main Stage Live Stream -->
<!DOCTYPE html>

<html class="dark" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>ENJOYFUN LIVE - Main Stage</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&amp;family=Manrope:wght@300;400;500;600;700&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            "colors": {
                    "inverse-primary": "#684cb6",
                    "tertiary-container": "#8342f4",
                    "surface": "#150629",
                    "on-secondary-container": "#e0ffec",
                    "on-primary-container": "#290070",
                    "tertiary-fixed": "#c0a0ff",
                    "primary-container": "#ab8ffe",
                    "on-secondary": "#005e40",
                    "surface-container": "#22103a",
                    "error-container": "#a70138",
                    "surface-container-low": "#1b0a31",
                    "primary-fixed": "#ab8ffe",
                    "primary-fixed-dim": "#9d81f0",
                    "surface-tint": "#b79fff",
                    "on-surface-variant": "#b7a3cf",
                    "surface-bright": "#372056",
                    "on-background": "#efdfff",
                    "on-error": "#490013",
                    "surface-dim": "#150629",
                    "on-secondary-fixed-variant": "#006948",
                    "surface-container-lowest": "#000000",
                    "on-tertiary-fixed-variant": "#4a00a4",
                    "error-dim": "#d73357",
                    "inverse-surface": "#fff7ff",
                    "on-primary": "#361083",
                    "secondary-container": "#006c4b",
                    "primary-dim": "#a88cfb",
                    "tertiary": "#af88ff",
                    "surface-container-highest": "#301a4d",
                    "secondary-dim": "#57edb1",
                    "on-secondary-fixed": "#004931",
                    "tertiary-dim": "#8a4cfc",
                    "on-primary-fixed-variant": "#330b80",
                    "on-primary-fixed": "#000000",
                    "tertiary-fixed-dim": "#b48fff",
                    "on-surface": "#efdfff",
                    "secondary-fixed": "#68fcbf",
                    "surface-container-high": "#291543",
                    "outline-variant": "#514166",
                    "on-tertiary-fixed": "#200051",
                    "outline": "#806e96",
                    "on-error-container": "#ffb2b9",
                    "on-tertiary-container": "#ffffff",
                    "background": "#150629",
                    "error": "#ff6e84",
                    "surface-variant": "#301a4d",
                    "secondary-fixed-dim": "#57edb1",
                    "primary": "#b79fff",
                    "inverse-on-surface": "#5e4e74",
                    "secondary": "#68fcbf",
                    "on-tertiary": "#2b0065"
            },
            "borderRadius": {
                    "DEFAULT": "0.125rem",
                    "lg": "0.25rem",
                    "xl": "0.5rem",
                    "full": "0.75rem"
            },
            "fontFamily": {
                    "headline": ["Space Grotesk"],
                    "body": ["Manrope"],
                    "label": ["Space Grotesk"]
            }
          },
        },
      }
    </script>
<style>
        body {
            background-color: #150629;
            color: #efdfff;
            font-family: 'Manrope', sans-serif;
            overflow-x: hidden;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .glass-panel {
            background: rgba(48, 26, 77, 0.4);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        .neon-glow-secondary {
            text-shadow: 0 0 10px rgba(104, 252, 191, 0.6);
        }
        .neon-border-primary {
            box-shadow: 0 0 15px rgba(183, 159, 255, 0.2);
        }
    </style>
<style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head>
<body class="bg-surface selection:bg-primary/30">
<!-- Top Navigation Bar (Shared Component Strategy) -->
<nav class="bg-violet-950/40 backdrop-blur-xl text-violet-400 font-['Space_Grotesk'] tracking-tight fixed top-0 w-full z-50 shadow-[0_0_20px_rgba(167,139,250,0.15)] flex justify-between items-center px-6 h-16">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined cursor-pointer hover:bg-violet-400/10 p-2 rounded-full transition-all duration-300">menu</span>
<span class="text-xl font-bold tracking-tighter text-transparent bg-clip-text bg-gradient-to-r from-violet-400 to-violet-200">ENJOYFUN LIVE</span>
</div>
<div class="flex items-center gap-4">
<div class="flex items-center bg-surface-container-highest/50 px-3 py-1 rounded-full border border-outline-variant/20">
<span class="w-2 h-2 rounded-full bg-secondary mr-2 shadow-[0_0_8px_#68fcbf]"></span>
<span class="text-[10px] uppercase tracking-widest font-bold">Main Stage</span>
</div>
<div class="w-8 h-8 rounded-full overflow-hidden border border-primary/30">
<img alt="Profile" data-alt="close-up portrait of a stylish young man with professional lighting for a user profile avatar" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBvW3Th5p7PimpUcWRxCcEJTzxP3k_Z-0-MjxX0dTR4oqeKIqoyLPLsAJJzOhyc6vVIRrja5GgBku3KVjdJNgzhHwPThPyr_8JO42qMO8qsBgNspnFo3R0CZtPGfbMmDj8gAVbUEK2sb76zdoCrIS7DRJrGnu32BpntkPgNpiK_jwWHfxc1cBmnZWrZq1lxoOxF4jUYNApoTYY7OdF3EL0JSHLZuuLHapG08nIJ-Z1COWtA3oi7n5sT2eXr8hSl6e6TF4RWTvYFtsg"/>
</div>
</div>
</nav>
<main class="pt-16 pb-24 min-h-screen flex flex-col">
<!-- Video Player Section (Hero) -->
<section class="relative w-full aspect-video md:max-h-[530px] overflow-hidden bg-black group">
<img alt="Live Stream" class="w-full h-full object-cover opacity-90 transition-transform duration-700 group-hover:scale-105" data-alt="cinematic wide shot of a world-class DJ performing on a massive stage with intense purple and cyan laser lights and a huge cheering crowd" src="https://lh3.googleusercontent.com/aida-public/AB6AXuAD2xGBSQf14y7vb40TJARcapn_9_f_c0RB7K5pCXo690_bAJsmBmzhcPyTWJddQBTxoG8DlxG0cfMH1RbLmFvFQpWICKweDOP0THWeXaEBgWR3DpZ8hRtl_yVQ9r2VGEbESbGMVDTAz3jwqptxGPK6XAQ428PXEL3M0r_A55xs98B1YIv7O4lniyREEXJaEKzE0Kqba4JotqipM6RsQdccewg9r2hF6eZXqZKiTRG18zrX0AfcYkQSzBSlMgFqdFmZA8jo5aDMd80"/>
<!-- Video Overlays -->
<div class="absolute inset-0 bg-gradient-to-t from-surface via-transparent to-surface/40"></div>
<div class="absolute top-6 left-6 flex items-center gap-3">
<div class="bg-error px-3 py-1 rounded-sm flex items-center gap-1.5 shadow-[0_0_15px_rgba(255,110,132,0.4)]">
<span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
<span class="text-[11px] font-bold font-headline tracking-tighter">LIVE</span>
</div>
<div class="bg-black/40 backdrop-blur-md px-3 py-1 rounded-sm flex items-center gap-2 border border-white/10">
<span class="material-symbols-outlined text-sm leading-none">visibility</span>
<span class="text-[11px] font-medium font-body">14.2K</span>
</div>
</div>
<button class="absolute top-6 right-6 w-10 h-10 rounded-full glass-panel border border-white/10 flex items-center justify-center text-white hover:scale-110 transition-transform shadow-xl">
<span class="material-symbols-outlined">map</span>
</button>
<!-- Playback Controls (Simulated) -->
<div class="absolute bottom-6 left-6 right-6 flex items-center justify-between opacity-0 group-hover:opacity-100 transition-opacity duration-300">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined cursor-pointer">pause</span>
<span class="material-symbols-outlined cursor-pointer">volume_up</span>
</div>
<span class="material-symbols-outlined cursor-pointer">fullscreen</span>
</div>
</section>
<!-- Main Content Area: Chat & Actions -->
<section class="flex-grow flex flex-col px-6 py-8 gap-8 max-w-5xl mx-auto w-full">
<!-- Header Info -->
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
<div>
<h1 class="text-4xl font-headline font-bold tracking-tight text-white mb-2">NEON VOYAGE: <span class="text-primary">SET 04</span></h1>
<p class="text-on-surface-variant font-body text-sm max-w-xl">Experience the celestial soundscapes of DJ NOVA live from the Andromeda Arena. 48 hours of non-stop galactic beats.</p>
</div>
<div class="flex items-center gap-3">
<button class="px-6 py-2.5 bg-gradient-to-br from-primary to-primary-container text-on-primary font-bold rounded-md shadow-[0_4px_15px_rgba(183,159,255,0.3)] hover:scale-105 transition-all">FOLLOW</button>
<button class="w-11 h-11 flex items-center justify-center border border-outline-variant/30 rounded-md hover:bg-surface-container-highest transition-colors">
<span class="material-symbols-outlined text-primary">share</span>
</button>
</div>
</div>
<!-- Bento Grid Content -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 flex-grow">
<!-- Chat Section (Spans 2 columns) -->
<div class="md:col-span-2 flex flex-col bg-surface-container-low rounded-xl border border-outline-variant/10 overflow-hidden h-[400px] shadow-2xl">
<div class="p-4 border-b border-outline-variant/10 bg-surface-container/50 flex items-center justify-between">
<span class="font-headline font-bold text-sm tracking-widest text-on-surface-variant uppercase">Live Stream Chat</span>
<span class="material-symbols-outlined text-on-surface-variant text-sm">settings</span>
</div>
<div class="flex-grow overflow-y-auto p-4 space-y-4 font-body scrollbar-hide">
<!-- Chat Messages -->
<div class="flex items-start gap-3">
<div class="w-8 h-8 rounded-full bg-tertiary-container flex-shrink-0 flex items-center justify-center text-[10px] font-bold">JS</div>
<div>
<span class="text-secondary font-bold text-xs block mb-0.5 neon-glow-secondary">SKY_WALKER_88</span>
<p class="text-sm text-on-surface">This drop is absolutely insane! 🌌🔥</p>
</div>
</div>
<div class="flex items-start gap-3">
<div class="w-8 h-8 rounded-full bg-primary-container flex-shrink-0 flex items-center justify-center text-[10px] font-bold">LX</div>
<div>
<span class="text-primary font-bold text-xs block mb-0.5">LUNA_X</span>
<p class="text-sm text-on-surface">Visuals on another level tonight.</p>
</div>
</div>
<div class="flex items-start gap-3">
<div class="w-8 h-8 rounded-full bg-secondary-container flex-shrink-0 flex items-center justify-center text-[10px] font-bold">ZR</div>
<div>
<span class="text-emerald-400 font-bold text-xs block mb-0.5">ZERO_GRAVITY</span>
<p class="text-sm text-on-surface">Loving the vibe from Tokyo! 🇯🇵🛸</p>
</div>
</div>
<div class="flex items-start gap-3 opacity-60">
<div class="w-8 h-8 rounded-full bg-surface-variant flex-shrink-0 flex items-center justify-center text-[10px] font-bold">ST</div>
<div>
<span class="text-on-surface-variant font-bold text-xs block mb-0.5">STAR_DUST</span>
<p class="text-sm text-on-surface">Who is the opener for the next set?</p>
</div>
</div>
<div class="flex items-start gap-3">
<div class="w-8 h-8 rounded-full bg-error-container flex-shrink-0 flex items-center justify-center text-[10px] font-bold">CR</div>
<div>
<span class="text-error font-bold text-xs block mb-0.5">COSMIC_RAY</span>
<p class="text-sm text-on-surface">FIRE FIRE FIRE 🌋🌋🌋</p>
</div>
</div>
</div>
<!-- Chat Input -->
<div class="p-4 bg-surface-container-lowest border-t border-outline-variant/10">
<div class="relative flex items-center">
<input class="w-full bg-surface-container-low border-none rounded-lg py-3 px-4 text-sm focus:ring-1 focus:ring-primary/50 placeholder:text-on-surface-variant/50" placeholder="Send a message..." type="text"/>
<div class="absolute right-3 flex items-center gap-2">
<span class="material-symbols-outlined text-on-surface-variant cursor-pointer hover:text-primary transition-colors">sentiment_satisfied</span>
<span class="material-symbols-outlined text-primary cursor-pointer">send</span>
</div>
</div>
</div>
</div>
<!-- Secondary Info Panel -->
<div class="flex flex-col gap-6">
<!-- Up Next Card -->
<div class="bg-surface-container-high rounded-xl p-5 border border-outline-variant/10 relative overflow-hidden group">
<div class="absolute -right-4 -top-4 w-24 h-24 bg-primary/10 blur-3xl group-hover:bg-primary/20 transition-all"></div>
<h3 class="font-headline font-bold text-xs tracking-widest text-primary uppercase mb-4">UP NEXT</h3>
<div class="flex items-center gap-4">
<img alt="Next Artist" class="w-14 h-14 rounded-md object-cover border border-white/5" data-alt="close-up of professional dj equipment with glowing lights and blurred background for an upcoming artist preview" src="https://lh3.googleusercontent.com/aida-public/AB6AXuACDJbIo1Mgs8d6HaoYjHgZsA6FmBe3_UPmei4FXO6t-hS8n8guaBO7F0-7usdc9EZoSKk8NS4_fjFR59dx5OZzABaYMnTby5YoLK4QvLh3cGNv6GjePImCEYp1vdyPZeFj0yz4n7GRO9gtZk4m5sYFwANqs_BHnF2ykdjso_0ooAdHnshFcKNN2YgerRqv9lq6LXlkgXJ6kidVPWKlmR03eOTV6nbsK8ytCjDwk3V4r-eMC-I5CRBO--Cvoy0P10k-eOZ8LfUKYmk"/>
<div>
<h4 class="font-bold text-white text-sm">VELOCITY X</h4>
<p class="text-[10px] text-on-surface-variant uppercase tracking-wider">Starts in 45m</p>
</div>
</div>
</div>
<!-- Lineup Brief -->
<div class="bg-surface-container-low rounded-xl p-5 border border-outline-variant/10">
<h3 class="font-headline font-bold text-xs tracking-widest text-on-surface-variant uppercase mb-4">NOW TRENDING</h3>
<ul class="space-y-4">
<li class="flex items-center justify-between text-xs">
<span class="text-on-surface-variant">#AndromedaStage</span>
<span class="text-secondary font-bold">12.4k</span>
</li>
<li class="flex items-center justify-between text-xs">
<span class="text-on-surface-variant">#DJNovaSet</span>
<span class="text-secondary font-bold">8.1k</span>
</li>
<li class="flex items-center justify-between text-xs">
<span class="text-on-surface-variant">#CyberVibes</span>
<span class="text-secondary font-bold">3.2k</span>
</li>
</ul>
</div>
</div>
</div>
</section>
</main>
<!-- Reaction Floating Bar -->
<div class="fixed bottom-24 left-1/2 -translate-x-1/2 z-40 bg-surface-container-highest/80 backdrop-blur-md px-6 py-3 rounded-full border border-white/10 shadow-[0_10px_30px_rgba(0,0,0,0.5)] flex items-center gap-6">
<button class="flex flex-col items-center gap-1 group">
<span class="material-symbols-outlined text-error group-hover:scale-125 transition-transform" style="font-variation-settings: 'FILL' 1;">local_fire_department</span>
<span class="text-[8px] font-bold text-error/80 uppercase">Fire</span>
</button>
<button class="flex flex-col items-center gap-1 group">
<span class="material-symbols-outlined text-pink-400 group-hover:scale-125 transition-transform" style="font-variation-settings: 'FILL' 1;">favorite</span>
<span class="text-[8px] font-bold text-pink-400/80 uppercase">Heart</span>
</button>
<button class="flex flex-col items-center gap-1 group">
<span class="material-symbols-outlined text-secondary group-hover:scale-125 transition-transform" style="font-variation-settings: 'FILL' 1;">bolt</span>
<span class="text-[8px] font-bold text-secondary/80 uppercase">Laser</span>
</button>
<button class="flex flex-col items-center gap-1 group">
<span class="material-symbols-outlined text-primary group-hover:scale-125 transition-transform" style="font-variation-settings: 'FILL' 1;">star</span>
<span class="text-[8px] font-bold text-primary/80 uppercase">Star</span>
</button>
</div>
<!-- Bottom Navigation (Shared Component Strategy) -->
<nav class="fixed bottom-0 left-0 w-full flex justify-around items-center h-20 px-4 pb-2 bg-violet-950/40 backdrop-blur-xl border-t border-violet-400/15 z-50 rounded-t-xl shadow-[0_-4px_20px_rgba(167,139,250,0.1)]">
<a class="flex flex-col items-center justify-center text-emerald-400 drop-shadow-[0_0_8px_rgba(104,252,191,0.6)] scale-105 transition-transform duration-200" href="#">
<span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">sensors</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-widest mt-1">Live</span>
</a>
<a class="flex flex-col items-center justify-center text-violet-300/60 hover:text-violet-200 transition-transform duration-200" href="#">
<span class="material-symbols-outlined">queue_music</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-widest mt-1">Lineup</span>
</a>
<a class="flex flex-col items-center justify-center text-violet-300/60 hover:text-violet-200 transition-transform duration-200" href="#">
<span class="material-symbols-outlined">forum</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-widest mt-1">Chat</span>
</a>
<a class="flex flex-col items-center justify-center text-violet-300/60 hover:text-violet-200 transition-transform duration-200" href="#">
<span class="material-symbols-outlined">account_circle</span>
<span class="font-['Manrope'] text-[10px] uppercase tracking-widest mt-1">Profile</span>
</a>
</nav>
</body></html>