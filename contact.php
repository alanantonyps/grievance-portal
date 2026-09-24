<?php
/**
 * contact.php
 * ---------------------------------------------------------------------------
 * Contact Us — Rajagiri College Grievance Redressal Portal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us — Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink:   '#E5097F',
            brandGreen:  '#006837',
            brandGold:   '#C5A059'
          },
          keyframes: {
            fadeInUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            swingA: { '0%, 100%': { transform: 'rotate(-6deg)' }, '50%': { transform: 'rotate(6deg)' } },
            swingB: { '0%, 100%': { transform: 'rotate(5deg)' },  '50%': { transform: 'rotate(-5deg)' } },
            swingC: { '0%, 100%': { transform: 'rotate(-4deg)' }, '50%': { transform: 'rotate(4deg)' } },
            swingD: { '0%, 100%': { transform: 'rotate(7deg)' },  '50%': { transform: 'rotate(-7deg)' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'swing-a': 'swingA 3.2s ease-in-out infinite',
            'swing-b': 'swingB 3.6s ease-in-out infinite',
            'swing-c': 'swingC 3.0s ease-in-out infinite',
            'swing-d': 'swingD 3.8s ease-in-out infinite'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased selection:bg-pink-100 selection:text-pink-700 flex flex-col">

  <!-- ====================== HEADER ====================== -->
  <header class="bg-white shadow-md sticky top-0 z-50 border-b-2 border-slate-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16 md:h-20">

        <!-- Logo -->
        <div class="flex items-center space-x-4">
          <a href="index.php" class="flex items-center space-x-3 group">
            <div class="relative">
              <img
                src="public/rcss-logo.png"
                alt="RCSS Logo"
                class="h-10 md:h-12 w-auto group-hover:scale-105 transition-transform duration-300"
              />
            </div>
            <div class="hidden sm:flex items-center space-x-2 pl-4 border-l-2 border-[#006837]">
              <img
                src="public/orel-grievance.png"
                alt="Oréll Grievance"
                class="h-8 md:h-10 w-auto"
              />
            </div>
          </a>
        </div>

        <!-- Right side: Back to Home button (desktop) -->
        <a
          href="index.php"
          class="hidden md:inline-flex items-center gap-2 px-5 py-2.5 rounded-lg
                 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F]
                 text-white text-sm font-bold
                 hover:shadow-xl hover:shadow-pink-500/30
                 transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                 ring-2 ring-transparent hover:ring-pink-300"
        >
          <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
          <span>Back to Home</span>
        </a>

        <!-- Mobile Menu Button -->
        <button
          id="mobile-menu-btn"
          type="button"
          aria-label="Toggle menu"
          aria-expanded="false"
          aria-controls="mobile-menu"
          class="md:hidden text-[#4A154B] hover:bg-slate-100 p-2 rounded-lg transition-colors cursor-pointer"
        >
          <i data-lucide="menu" id="mobile-menu-icon" class="w-6 h-6"></i>
        </button>
      </div>
    </div>

    <!-- Mobile Menu Drawer -->
    <div id="mobile-menu" class="hidden md:hidden bg-white border-t-2 border-slate-100 shadow-xl">
      <div class="px-4 py-4">
        <a
          href="index.php"
          class="flex items-center gap-3 px-4 py-3 rounded-lg
                 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F]
                 text-white text-sm font-bold
                 transition-all duration-200 active:scale-95"
        >
          <i data-lucide="arrow-left" class="w-4 h-4"></i>
          <span>Back to Home</span>
        </a>
      </div>
    </div>
  </header>

  <!-- ====================== HERO BANNER ====================== -->
  <section class="relative overflow-hidden bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F]">
    <div class="absolute inset-0 opacity-10 pointer-events-none">
      <div class="absolute top-0 left-0 w-full h-full hero-radial-dots"></div>
    </div>

    <div class="absolute top-20 left-10 w-72 h-72 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-20 right-10 w-96 h-96 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute top-1/3 right-1/4 w-48 h-48 bg-yellow-500/20 rounded-full blur-2xl pointer-events-none"></div>

    <div class="absolute inset-0 opacity-5 hero-grid-lines pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 md:py-20">
      <div class="flex flex-col md:flex-row items-center justify-between gap-10 md:gap-12">

        <!-- Left: heading -->
        <div class="text-center md:text-left animate-fade-in-up">
          <div class="inline-flex items-center space-x-2 bg-white/15 backdrop-blur-md border border-white/30 rounded-full px-5 py-2.5 mb-6">
            <i data-lucide="headphones" class="w-4 h-4 text-[#C5A059]"></i>
            <span class="text-white text-xs md:text-sm font-semibold tracking-wide">We're Here to Help</span>
          </div>

          <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-4 tracking-tight">
            Contact Us
          </h1>
          <p class="text-base md:text-lg text-white/90 max-w-xl mx-auto md:mx-0 leading-relaxed">
            Have a question, feedback, or need support with the grievance portal?
            Reach out to the RCSS Grievance Redressal team — we're happy to assist.
          </p>

          <div class="mt-6 flex flex-wrap items-center justify-center md:justify-start gap-3">
            <a href="#map" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl
                                 bg-white text-[#4A154B] font-bold text-sm
                                 shadow-lg shadow-black/10
                                 hover:shadow-xl hover:-translate-y-0.5
                                 transition-all duration-300 active:scale-95">
              <i data-lucide="map-pin" class="w-4 h-4 text-[#E5097F]"></i>
              <span>View Location</span>
            </a>
            <a href="index.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl
                                      bg-white/10 hover:bg-white/20 backdrop-blur-md
                                      border border-white/30 hover:border-white/60
                                      text-white font-semibold text-sm
                                      transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="home" class="w-4 h-4"></i>
              <span>Back to Home</span>
            </a>
          </div>
        </div>

        <!-- Right: hanging icons -->
        <div class="relative flex items-start justify-center gap-4 sm:gap-6 md:gap-8 pt-4 md:pt-2">

          <!-- Icon 1: Mail -->
          <div class="flex flex-col items-center origin-top animate-swing-a">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-[#6A2C8A] rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-gradient-to-br from-[#B57BE8] to-[#7E3FBF]
                          flex items-center justify-center shadow-xl shadow-purple-900/40
                          ring-2 ring-white/40">
                <i data-lucide="mail" class="w-7 h-7 md:w-9 md:h-9 text-white"></i>
              </div>
            </div>
          </div>

          <!-- Icon 2: Phone -->
          <div class="flex flex-col items-center origin-top animate-swing-b mt-2">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-[#6A2C8A] rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-gradient-to-br from-[#B57BE8] to-[#7E3FBF]
                          flex items-center justify-center shadow-xl shadow-purple-900/40
                          ring-2 ring-white/40">
                <i data-lucide="phone" class="w-7 h-7 md:w-9 md:h-9 text-white"></i>
              </div>
            </div>
          </div>

          <!-- Icon 3: Mobile -->
          <div class="flex flex-col items-center origin-top animate-swing-c mt-4">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-[#6A2C8A] rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-gradient-to-br from-[#B57BE8] to-[#7E3FBF]
                          flex items-center justify-center shadow-xl shadow-purple-900/40
                          ring-2 ring-white/40">
                <i data-lucide="smartphone" class="w-7 h-7 md:w-9 md:h-9 text-white"></i>
              </div>
            </div>
          </div>

          <!-- Icon 4: @ Symbol -->
          <div class="flex flex-col items-center origin-top animate-swing-d mt-1">
            <div class="w-[2px] h-12 md:h-16 bg-gradient-to-b from-white/70 to-white/20"></div>
            <div class="relative -mt-1">
              <div class="absolute inset-0 bg-[#6A2C8A] rounded-full blur-md opacity-70"></div>
              <div class="relative w-16 h-16 md:w-20 md:h-20 rounded-full
                          bg-gradient-to-br from-[#B57BE8] to-[#7E3FBF]
                          flex items-center justify-center shadow-xl shadow-purple-900/40
                          ring-2 ring-white/40">
                <span class="text-white text-3xl md:text-4xl font-bold leading-none">@</span>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-yellow-400 to-transparent"></div>
  </section>

  <!-- ====================== ADDRESS CARD ====================== -->
  <section class="relative -mt-8 md:-mt-12 z-10">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
      <div class="bg-white border-2 border-[#4A154B]/20 rounded-3xl shadow-2xl px-6 py-8 md:px-10 md:py-10 text-center animate-fade-in-up">

        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl
                    bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F]
                    shadow-lg shadow-purple-500/30 mb-4">
          <i data-lucide="map-pin" class="w-7 h-7 text-white"></i>
        </div>

        <h2 class="text-2xl md:text-3xl font-bold text-[#4A154B] mb-4">
          Contact Address
        </h2>

        <p class="text-base md:text-lg font-semibold text-slate-800">
          Rajagiri College of Social Sciences
        </p>
        <p class="text-sm md:text-base text-slate-600 mt-1 leading-relaxed">
          Rajagiri P.O., Kalamassery,<br class="sm:hidden" />
          Cochin – 683 104, Kerala, India
        </p>

        <div class="mt-6 flex items-center justify-center">
          <a href="https://www.google.com/maps/dir/?api=1&destination=Rajagiri+College+of+Social+Sciences+Kalamassery"
             target="_blank" rel="noopener"
             class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl
                    bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F]
                    text-white font-semibold text-sm
                    shadow-md hover:shadow-xl shadow-pink-500/30
                    transition-all duration-300
                    hover:-translate-y-0.5 active:scale-95">
            <i data-lucide="navigation" class="w-4 h-4"></i>
            <span>Get Directions</span>
          </a>
        </div>

      </div>
    </div>
  </section>

  <!-- ====================== CONTACT INFO TILES ====================== -->
  <section class="pt-12 md:pt-16 pb-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-10 animate-fade-in-up">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                     bg-[#4A154B]/10 text-[#4A154B] text-xs font-bold uppercase tracking-wider">
          <i data-lucide="phone-call" class="w-3.5 h-3.5"></i>
          <span>Get in Touch</span>
        </span>
        <h2 class="text-3xl md:text-4xl font-bold text-[#4A154B] mt-3 mb-2">Multiple Ways to Reach Us</h2>
        <div class="flex items-center justify-center space-x-2 mb-3">
          <span class="w-16 h-1 bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] rounded-full"></span>
          <span class="w-3 h-3 bg-[#E5097F] rounded-full"></span>
          <span class="w-16 h-1 bg-gradient-to-r from-[#E5097F] to-[#8B1E7E] rounded-full"></span>
        </div>
        <p class="text-slate-600 max-w-2xl mx-auto">
          Choose the channel that works best for you — we're here to help.
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8">

        <!-- Email -->
        <div class="group bg-white rounded-2xl border-2 border-slate-100 hover:border-[#8B1E7E]
                    shadow-md hover:shadow-2xl transition-all duration-500 hover:-translate-y-1 p-6 text-center relative overflow-hidden">
          <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-purple-100/0 to-pink-100/0 group-hover:from-purple-100/40 group-hover:to-pink-100/40 rounded-full transition-all duration-500"></div>

          <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-2xl
                      bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                      shadow-lg shadow-purple-500/30 mb-4 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500">
            <i data-lucide="mail" class="w-8 h-8 text-white"></i>
          </div>
          <h3 class="text-sm font-bold uppercase tracking-wider text-[#4A154B] mb-3 relative">Email Us</h3>
          <p class="text-slate-600 text-sm leading-relaxed relative">
            <a href="mailto:grievance@rajagiri.edu" class="hover:text-[#E5097F] font-medium transition-colors">
              grievance@rajagiri.edu
            </a>
          </p>
          <p class="text-slate-600 text-sm leading-relaxed relative mt-1">
            <a href="mailto:principal@rajagiri.edu" class="hover:text-[#E5097F] font-medium transition-colors">
              principal@rajagiri.edu
            </a>
          </p>
        </div>

        <!-- Phone -->
        <div class="group bg-white rounded-2xl border-2 border-slate-100 hover:border-[#006837]
                    shadow-md hover:shadow-2xl transition-all duration-500 hover:-translate-y-1 p-6 text-center relative overflow-hidden">
          <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-emerald-100/0 to-green-100/0 group-hover:from-emerald-100/40 group-hover:to-green-100/40 rounded-full transition-all duration-500"></div>

          <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-2xl
                      bg-gradient-to-br from-[#006837] to-[#008a4a]
                      shadow-lg shadow-emerald-500/30 mb-4 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500">
            <i data-lucide="phone" class="w-8 h-8 text-white"></i>
          </div>
          <h3 class="text-sm font-bold uppercase tracking-wider text-[#006837] mb-3 relative">Call Us</h3>
          <p class="text-slate-600 text-sm leading-relaxed relative">
            <a href="tel:+914842554000" class="hover:text-[#E5097F] font-medium transition-colors">
              +91 484 255 4000
            </a>
          </p>
          <p class="text-slate-600 text-sm leading-relaxed relative mt-1">
            <a href="tel:+914842554100" class="hover:text-[#E5097F] font-medium transition-colors">
              +91 484 255 4100
            </a>
          </p>
        </div>

        <!-- Address -->
        <div class="group bg-white rounded-2xl border-2 border-slate-100 hover:border-[#E5097F]
                    shadow-md hover:shadow-2xl transition-all duration-500 hover:-translate-y-1 p-6 text-center relative overflow-hidden">
          <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-pink-100/0 to-purple-100/0 group-hover:from-pink-100/40 group-hover:to-purple-100/40 rounded-full transition-all duration-500"></div>

          <div class="relative inline-flex items-center justify-center w-16 h-16 rounded-2xl
                      bg-gradient-to-br from-[#E5097F] to-[#C43A7A]
                      shadow-lg shadow-pink-500/30 mb-4 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500">
            <i data-lucide="map-pin" class="w-8 h-8 text-white"></i>
          </div>
          <h3 class="text-sm font-bold uppercase tracking-wider text-[#E5097F] mb-3 relative">Visit Us</h3>
          <p class="text-slate-600 text-sm leading-relaxed relative">
            Rajagiri P.O., Kalamassery,<br />
            Cochin – 683 104,<br />
            Kerala, India
          </p>
        </div>

      </div>
    </div>
  </section>

  <!-- ====================== GOOGLE MAP ====================== -->
  <section id="map" class="pb-14 md:pb-20 scroll-mt-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-6 animate-fade-in-up">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                     bg-[#4A154B]/10 text-[#4A154B] text-xs font-bold uppercase tracking-wider">
          <i data-lucide="map" class="w-3.5 h-3.5"></i>
          <span>Locate Us</span>
        </span>
        <h2 class="text-2xl md:text-3xl font-bold text-[#4A154B] mt-3 tracking-tight">
          Find Us on the Map
        </h2>
        <p class="text-slate-500 text-sm mt-2 max-w-xl mx-auto">
          Rajagiri College of Social Sciences, Kalamassery, Kochi — open in Google Maps for directions.
        </p>
      </div>

      <div class="relative rounded-3xl overflow-hidden shadow-2xl border-4 border-white
                  ring-1 ring-slate-200 bg-white">
        <iframe
          title="Rajagiri College of Social Sciences Location"
          src="https://www.google.com/maps?q=Rajagiri%20College%20of%20Social%20Sciences%20Kalamassery&output=embed"
          width="100%"
          height="480"
          style="border:0;"
          allowfullscreen=""
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          class="block w-full h-[360px] md:h-[480px]"
        ></iframe>

        <!-- Map overlay badge (right side) -->
        <div class="absolute top-4 right-4 bg-white/95 backdrop-blur-sm rounded-xl
                    shadow-lg border border-slate-200 px-4 py-3 flex items-center gap-3 max-w-xs">
          <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-[#006837] to-[#008a4a]
                      flex items-center justify-center flex-shrink-0">
            <i data-lucide="school" class="w-5 h-5 text-white"></i>
          </div>
          <div class="min-w-0">
            <p class="text-xs font-bold text-slate-800 truncate">Rajagiri College of Social Sciences</p>
            <p class="text-[11px] text-slate-500 truncate">Kalamassery, Kochi, Kerala</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ====================== FOOTER ====================== -->
  <footer class="bg-gradient-to-br from-[#4A154B] via-[#3a1040] to-[#2a0a30] text-white py-12 relative overflow-hidden mt-auto">
    <div class="absolute top-0 right-0 w-96 h-96 bg-[#E5097F]/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 w-96 h-96 bg-[#006837]/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">

        <div>
          <div class="flex items-center space-x-3 mb-4">
            <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-12 w-auto" />
            <img src="public/orel-grievance.png" alt="Oréll Grievance" class="h-8 w-auto" />
          </div>
          <p class="text-slate-300 text-sm leading-relaxed">
            Rajagiri College of Social Sciences - Committed to excellence in grievance redressal, powered by Oréll.
          </p>
        </div>

        <div>
          <h4 class="font-bold mb-4 text-[#C5A059]">Quick Links</h4>
          <ul class="space-y-2 text-sm">
            <li>
              <a href="login.php?role=student" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>Student</span>
              </a>
            </li>
            <li>
              <a href="login.php?role=parent" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>Parent</span>
              </a>
            </li>
            <li>
              <a href="login.php?role=staff" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>Staff</span>
              </a>
            </li>
            <li>
              <a href="login.php?role=management" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>Grievance Member</span>
              </a>
            </li>
          </ul>
        </div>

        <div>
          <h4 class="font-bold mb-4 text-[#C5A059]">Contact</h4>
          <ul class="space-y-3 text-sm text-slate-300">
            <li class="flex items-center space-x-2">
              <i data-lucide="mail" class="w-4 h-4 text-[#E5097F]"></i>
              <span>grievance@rajagiri.edu</span>
            </li>
            <li class="flex items-center space-x-2">
              <i data-lucide="phone" class="w-4 h-4 text-[#E5097F]"></i>
              <span>+91 484 255 4000</span>
            </li>
            <li class="flex items-center space-x-2">
              <i data-lucide="map-pin" class="w-4 h-4 text-[#E5097F]"></i>
              <span>Kalamassery, Kochi, Kerala</span>
            </li>
          </ul>
        </div>

      </div>

      <div class="border-t border-white/10 pt-8 text-center text-sm text-slate-400">
        <p>
          &copy; <?= date('Y') ?>
          <span class="font-bold text-[#C5A059]">Rajagiri College of Social Sciences</span>.
          All rights reserved.
        </p>
        <p class="mt-2 text-xs">
          Powered by
          <span class="font-bold bg-gradient-to-r from-[#C5A059] to-[#E5097F] bg-clip-text text-transparent ml-1">
            Oréll Grievance
          </span>
        </p>
      </div>
    </div>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }

      // ---- Mobile menu toggle ----
      (function () {
        const btn  = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        const icon = document.getElementById('mobile-menu-icon');
        if (!btn || !menu) return;

        btn.addEventListener('click', function () {
          const isOpen = !menu.classList.contains('hidden');
          menu.classList.toggle('hidden');
          btn.setAttribute('aria-expanded', String(!isOpen));
          if (icon) {
            icon.setAttribute('data-lucide', isOpen ? 'menu' : 'x');
            if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [icon] });
          }
        });
      })();

      window.scrollTo(0, 0);
    });
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>