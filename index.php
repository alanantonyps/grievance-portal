<?php
// Start session and include database connection
session_start();
require_once 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Rajagiri College of Social Sciences - Grievance Redressal Portal. Submit and track grievances securely.">
  <title>Rajagiri College of Social Sciences - Grievance Redressal Portal</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg">
  
  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <!-- Custom Tailwind Theme Config -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059'
          }
        }
      }
    }
  </script>
  
  <!-- Local Page CSS Link -->
  <link rel="stylesheet" href="assets/css/index.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased selection:bg-pink-100 selection:text-pink-700">

  <!-- Header -->
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

        <!-- Desktop Navigation -->
        <nav class="hidden md:flex items-center space-x-8">
          <a 
            href="#" 
            class="relative text-slate-700 hover:text-[#006837] transition-colors text-sm font-semibold tracking-wide group"
          >
            UGC Guidelines
            <span class="absolute -bottom-2 left-0 w-full h-0.5 bg-[#006837] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-left"></span>
          </a>
          <a 
            href="#contact" 
            class="relative text-slate-700 hover:text-[#006837] transition-colors text-sm font-semibold tracking-wide group"
          >
            Contact
            <span class="absolute -bottom-2 left-0 w-full h-0.5 bg-[#006837] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300 origin-left"></span>
          </a>
          
          <!-- Login Dropdown -->
          <div class="relative" id="login-dropdown-container">
            <button
              id="login-dropdown-btn"
              type="button"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="login-dropdown-menu"
              class="bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] text-white px-6 py-2.5 rounded-lg text-sm font-bold hover:shadow-xl hover:shadow-pink-500/30 transition-all duration-300 flex items-center space-x-2 ring-2 ring-transparent hover:ring-pink-300 cursor-pointer"
            >
              <span>Login</span>
              <i data-lucide="chevron-down" id="login-chevron" class="w-4 h-4 transition-transform duration-300"></i>
            </button>
            
            <div id="login-dropdown-menu" class="hidden absolute right-0 mt-3 w-72 bg-white rounded-xl shadow-2xl border border-slate-200 z-50 py-2 overflow-hidden" role="menu">
              <div class="px-4 py-3 bg-gradient-to-r from-[#4A154B] to-[#8B1E7E]">
                <p class="text-white text-xs font-bold uppercase tracking-wider">Login As</p>
              </div>
              <a
                href="login.php?role=admin"
                class="group flex items-center space-x-3 px-4 py-3 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 border-b border-slate-50"
                role="menuitem"
              >
                <i data-lucide="lock" class="w-4 h-4 text-[#8B1E7E]"></i>
                <span class="font-medium">Admin</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a
                href="login.php?role=member"
                class="group flex items-center space-x-3 px-4 py-3 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 border-b border-slate-50"
                role="menuitem"
              >
                <i data-lucide="layers" class="w-4 h-4 text-[#8B1E7E]"></i>
                <span class="font-medium">Grievance Member</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a
                href="login.php?role=faculty"
                class="group flex items-center space-x-3 px-4 py-3 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 border-b border-slate-50"
                role="menuitem"
              >
                <i data-lucide="briefcase" class="w-4 h-4 text-[#8B1E7E]"></i>
                <span class="font-medium">Teachers & Non-Teaching Staffs</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a
                href="login.php?role=parent"
                class="group flex items-center space-x-3 px-4 py-3 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 border-b border-slate-50"
                role="menuitem"
              >
                <i data-lucide="users" class="w-4 h-4 text-[#8B1E7E]"></i>
                <span class="font-medium">Parents</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
              <a
                href="login.php?role=student"
                class="group flex items-center space-x-3 px-4 py-3 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200"
                role="menuitem"
              >
                <i data-lucide="graduation-cap" class="w-4 h-4 text-[#8B1E7E]"></i>
                <span class="font-medium">Students</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>
            </div>
          </div>
        </nav>

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
      <div class="px-4 py-4 space-y-2">
        <a href="#" class="block text-slate-700 hover:text-[#006837] text-sm font-semibold px-4 py-3 hover:bg-slate-50 rounded-lg transition-colors">
          UGC Guidelines
        </a>
        <a href="#contact" class="block text-slate-700 hover:text-[#006837] text-sm font-semibold px-4 py-3 hover:bg-slate-50 rounded-lg transition-colors">
          Contact
        </a>
        
        <div class="border-t-2 border-slate-100 pt-3 mt-3">
          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 px-4">Login As</p>
          <a
            href="login.php?role=admin"
            class="flex items-center space-x-3 px-4 py-3 text-slate-700 hover:text-[#8B1E7E] hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 rounded-lg text-sm font-medium transition-all duration-200"
          >
            <i data-lucide="lock" class="w-4 h-4 text-[#8B1E7E]"></i>
            <span>Admin</span>
          </a>
          <a
            href="login.php?role=member"
            class="flex items-center space-x-3 px-4 py-3 text-slate-700 hover:text-[#8B1E7E] hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 rounded-lg text-sm font-medium transition-all duration-200"
          >
            <i data-lucide="layers" class="w-4 h-4 text-[#8B1E7E]"></i>
            <span>Grievance Member</span>
          </a>
          <a
            href="login.php?role=faculty"
            class="flex items-center space-x-3 px-4 py-3 text-slate-700 hover:text-[#8B1E7E] hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 rounded-lg text-sm font-medium transition-all duration-200"
          >
            <i data-lucide="briefcase" class="w-4 h-4 text-[#8B1E7E]"></i>
            <span>Teachers & Non-Teaching Staffs</span>
          </a>
          <a
            href="login.php?role=parent"
            class="flex items-center space-x-3 px-4 py-3 text-slate-700 hover:text-[#8B1E7E] hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 rounded-lg text-sm font-medium transition-all duration-200"
          >
            <i data-lucide="users" class="w-4 h-4 text-[#8B1E7E]"></i>
            <span>Parents</span>
          </a>
          <a
            href="login.php?role=student"
            class="flex items-center space-x-3 px-4 py-3 text-slate-700 hover:text-[#8B1E7E] hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 rounded-lg text-sm font-medium transition-all duration-200"
          >
            <i data-lucide="graduation-cap" class="w-4 h-4 text-[#8B1E7E]"></i>
            <span>Students</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <!-- Hero Banner -->
  <section class="relative overflow-hidden bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F]">
    <div class="absolute inset-0 opacity-10 pointer-events-none">
      <div class="absolute top-0 left-0 w-full h-full hero-radial-dots"></div>
    </div>
    
    <div class="absolute top-20 left-10 w-72 h-72 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-20 right-10 w-96 h-96 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute top-1/3 right-1/4 w-48 h-48 bg-yellow-500/20 rounded-full blur-2xl pointer-events-none"></div>
    
    <div class="absolute inset-0 opacity-5 hero-grid-lines pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28">
      <div class="text-center max-w-4xl mx-auto">
        <div class="inline-flex items-center space-x-2 bg-white/15 backdrop-blur-md border border-white/30 rounded-full px-5 py-2.5 mb-8">
          <i data-lucide="sparkles" class="w-4 h-4 text-[#C5A059]"></i>
          <span class="text-white text-sm font-semibold tracking-wide">Powered by Oréll Grievance</span>
        </div>
        
        <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-6 leading-tight">
          Fair, Transparent & Prompt
          <br />
          <span class="relative inline-block mt-2">
            <span class="bg-gradient-to-r from-[#C5A059] via-yellow-300 to-[#C5A059] bg-clip-text text-transparent">
              Grievance Resolution
            </span>
            <span class="absolute -bottom-2 left-0 w-full h-1 bg-gradient-to-r from-transparent via-yellow-400 to-transparent"></span>
          </span>
        </h1>
        
        <p class="text-lg md:text-xl text-white/95 mb-10 max-w-3xl mx-auto leading-relaxed">
          Your voice matters. Submit your concerns with confidence and track their resolution in real-time through our transparent and secure platform.
        </p>
        
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
          <button
            data-scroll-to="choose-portal"
            class="group inline-flex items-center space-x-3 bg-white text-[#4A154B] px-8 py-4 rounded-xl text-lg font-bold hover:shadow-2xl hover:shadow-white/30 transition-all duration-300 transform hover:-translate-y-1 cursor-pointer"
          >
            <i data-lucide="file-text" class="w-5 h-5 text-[#E5097F]"></i>
            <span>File a Grievance Now</span>
            <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-2 transition-transform duration-300"></i>
          </button>
        </div>
      </div>
    </div>
    
    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-yellow-400 to-transparent"></div>
  </section>

  <!-- Role Selection Cards -->
  <section id="choose-portal" class="py-16 md:py-20 bg-gradient-to-b from-slate-50 to-white scroll-mt-24">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-14">
        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-[#4A154B] mb-4">
          Choose Your Portal
        </h2>
        <div class="flex items-center justify-center space-x-2 mb-4">
          <span class="w-16 h-1 bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] rounded-full"></span>
          <span class="w-3 h-3 bg-[#E5097F] rounded-full"></span>
          <span class="w-16 h-1 bg-gradient-to-r from-[#E5097F] to-[#8B1E7E] rounded-full"></span>
        </div>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto">
          Select your role to access the appropriate grievance management interface
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6">
        
        <!-- Student Portal Card -->
        <a
          href="login.php?role=student"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 overflow-hidden border-2 border-slate-100 hover:border-[#8B1E7E] portal-card"
        >
          <div class="bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] p-6 text-white relative overflow-hidden">
            <div class="absolute -top-8 -right-8 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="absolute -bottom-8 -left-8 w-28 h-28 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700 delay-75"></div>
            <div class="absolute top-1/2 right-1/4 w-16 h-16 bg-white/5 rounded-full group-hover:scale-125 transition-transform duration-500"></div>
            
            <div class="relative">
              <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/20 backdrop-blur-sm mb-4 group-hover:bg-white/30 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-white/30">
                <i data-lucide="graduation-cap" class="w-7 h-7"></i>
              </div>
              <h3 class="text-lg font-bold mb-1 group-hover:translate-x-1 transition-transform duration-300">Student Portal</h3>
              <p class="text-xs text-white/80 font-medium">View & Track Grievances</p>
            </div>
          </div>
          
          <div class="p-6 relative">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-50/0 to-pink-50/0 group-hover:from-purple-50/40 group-hover:to-pink-50/40 transition-all duration-500 pointer-events-none"></div>
            
            <p class="text-slate-600 text-sm leading-relaxed mb-4 relative">
              Access grievance submission and tracking specifically for enrolled students
            </p>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 relative">
              <span class="text-[#006837] font-bold text-sm group-hover:text-[#008a4a] transition-colors">
                Access Portal
              </span>
              <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-[#E5097F]/10 transition-colors">
                <i data-lucide="arrow-right" class="w-5 h-5 text-[#E5097F] group-hover:translate-x-1 transition-transform duration-300"></i>
              </div>
            </div>
          </div>
          
          <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
        </a>

        <!-- Parent Portal Card -->
        <a
          href="login.php?role=parent"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 overflow-hidden border-2 border-slate-100 hover:border-[#8B1E7E] portal-card"
        >
          <div class="bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] p-6 text-white relative overflow-hidden">
            <div class="absolute -top-8 -right-8 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="absolute -bottom-8 -left-8 w-28 h-28 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700 delay-75"></div>
            <div class="absolute top-1/2 right-1/4 w-16 h-16 bg-white/5 rounded-full group-hover:scale-125 transition-transform duration-500"></div>
            
            <div class="relative">
              <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/20 backdrop-blur-sm mb-4 group-hover:bg-white/30 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-white/30">
                <i data-lucide="users" class="w-7 h-7"></i>
              </div>
              <h3 class="text-lg font-bold mb-1 group-hover:translate-x-1 transition-transform duration-300">Parent Portal</h3>
              <p class="text-xs text-white/80 font-medium">Monitor Ward Progress</p>
            </div>
          </div>
          
          <div class="p-6 relative">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-50/0 to-pink-50/0 group-hover:from-purple-50/40 group-hover:to-pink-50/40 transition-all duration-500 pointer-events-none"></div>
            
            <p class="text-slate-600 text-sm leading-relaxed mb-4 relative">
              Dedicated portal for parents to submit and monitor grievances regarding their wards
            </p>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 relative">
              <span class="text-[#006837] font-bold text-sm group-hover:text-[#008a4a] transition-colors">
                Access Portal
              </span>
              <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-[#E5097F]/10 transition-colors">
                <i data-lucide="arrow-right" class="w-5 h-5 text-[#E5097F] group-hover:translate-x-1 transition-transform duration-300"></i>
              </div>
            </div>
          </div>
          
          <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
        </a>

        <!-- Teachers & Non-Teaching Staffs Card -->
        <a
          href="login.php?role=faculty"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 overflow-hidden border-2 border-slate-100 hover:border-[#8B1E7E] portal-card"
        >
          <div class="bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] p-6 text-white relative overflow-hidden">
            <div class="absolute -top-8 -right-8 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="absolute -bottom-8 -left-8 w-28 h-28 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700 delay-75"></div>
            <div class="absolute top-1/2 right-1/4 w-16 h-16 bg-white/5 rounded-full group-hover:scale-125 transition-transform duration-500"></div>
            
            <div class="relative">
              <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/20 backdrop-blur-sm mb-4 group-hover:bg-white/30 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-white/30">
                <i data-lucide="briefcase" class="w-7 h-7"></i>
              </div>
              <h3 class="text-lg font-bold mb-1 group-hover:translate-x-1 transition-transform duration-300">Teachers & Staff</h3>
              <p class="text-xs text-white/80 font-medium">Priority Resolution</p>
            </div>
          </div>
          
          <div class="p-6 relative">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-50/0 to-pink-50/0 group-hover:from-purple-50/40 group-hover:to-pink-50/40 transition-all duration-500 pointer-events-none"></div>
            
            <p class="text-slate-600 text-sm leading-relaxed mb-4 relative">
              Submit and manage grievances for teaching and non-teaching staff members
            </p>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 relative">
              <span class="text-[#006837] font-bold text-sm group-hover:text-[#008a4a] transition-colors">
                Access Portal
              </span>
              <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-[#E5097F]/10 transition-colors">
                <i data-lucide="arrow-right" class="w-5 h-5 text-[#E5097F] group-hover:translate-x-1 transition-transform duration-300"></i>
              </div>
            </div>
          </div>
          
          <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
        </a>

        <!-- Grievance Member Card -->
        <a
          href="login.php?role=member"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 overflow-hidden border-2 border-slate-100 hover:border-[#8B1E7E] portal-card"
        >
          <div class="bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] p-6 text-white relative overflow-hidden">
            <div class="absolute -top-8 -right-8 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="absolute -bottom-8 -left-8 w-28 h-28 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700 delay-75"></div>
            <div class="absolute top-1/2 right-1/4 w-16 h-16 bg-white/5 rounded-full group-hover:scale-125 transition-transform duration-500"></div>
            
            <div class="relative">
              <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/20 backdrop-blur-sm mb-4 group-hover:bg-white/30 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-white/30">
                <i data-lucide="layers" class="w-7 h-7"></i>
              </div>
              <h3 class="text-lg font-bold mb-1 group-hover:translate-x-1 transition-transform duration-300">Grievance Member</h3>
              <p class="text-xs text-white/80 font-medium">Committee Dashboard</p>
            </div>
          </div>
          
          <div class="p-6 relative">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-50/0 to-pink-50/0 group-hover:from-purple-50/40 group-hover:to-pink-50/40 transition-all duration-500 pointer-events-none"></div>
            
            <p class="text-slate-600 text-sm leading-relaxed mb-4 relative">
              Administrative panel for grievance resolution, oversight, and committee management
            </p>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 relative">
              <span class="text-[#006837] font-bold text-sm group-hover:text-[#008a4a] transition-colors">
                Access Portal
              </span>
              <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-[#E5097F]/10 transition-colors">
                <i data-lucide="arrow-right" class="w-5 h-5 text-[#E5097F] group-hover:translate-x-1 transition-transform duration-300"></i>
              </div>
            </div>
          </div>
          
          <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
        </a>

        <!-- Admin Portal Card -->
        <a
          href="login.php?role=admin"
          class="group relative bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 overflow-hidden border-2 border-slate-100 hover:border-[#8B1E7E] portal-card"
        >
          <div class="bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] p-6 text-white relative overflow-hidden">
            <div class="absolute -top-8 -right-8 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700"></div>
            <div class="absolute -bottom-8 -left-8 w-28 h-28 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-700 delay-75"></div>
            <div class="absolute top-1/2 right-1/4 w-16 h-16 bg-white/5 rounded-full group-hover:scale-125 transition-transform duration-500"></div>
            
            <div class="relative">
              <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/20 backdrop-blur-sm mb-4 group-hover:bg-white/30 group-hover:scale-110 group-hover:rotate-6 transition-all duration-500 ring-1 ring-white/30">
                <i data-lucide="lock" class="w-7 h-7"></i>
              </div>
              <h3 class="text-lg font-bold mb-1 group-hover:translate-x-1 transition-transform duration-300">Admin Portal</h3>
              <p class="text-xs text-white/80 font-medium">Full System Control</p>
            </div>
          </div>
          
          <div class="p-6 relative">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-50/0 to-pink-50/0 group-hover:from-purple-50/40 group-hover:to-pink-50/40 transition-all duration-500 pointer-events-none"></div>
            
            <p class="text-slate-600 text-sm leading-relaxed mb-4 relative">
              Super admin access for system configuration, user management, and global oversight
            </p>
            <div class="flex items-center justify-between border-t border-slate-100 pt-4 relative">
              <span class="text-[#006837] font-bold text-sm group-hover:text-[#008a4a] transition-colors">
                Access Portal
              </span>
              <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center group-hover:bg-[#E5097F]/10 transition-colors">
                <i data-lucide="arrow-right" class="w-5 h-5 text-[#E5097F] group-hover:translate-x-1 transition-transform duration-300"></i>
              </div>
            </div>
          </div>
          
          <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
        </a>

      </div>
    </div>
  </section>

  <!-- Key Highlights Grid -->
  <section class="py-16 md:py-20 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-14">
        <h2 class="text-3xl md:text-4xl lg:text-5xl font-bold text-[#4A154B] mb-4">
          Why Choose Our Portal?
        </h2>
        <div class="flex items-center justify-center space-x-2 mb-4">
          <span class="w-16 h-1 bg-gradient-to-r from-[#006837] to-[#008a4a] rounded-full"></span>
          <span class="w-3 h-3 bg-[#006837] rounded-full"></span>
          <span class="w-16 h-1 bg-gradient-to-r from-[#008a4a] to-[#006837] rounded-full"></span>
        </div>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto">
          Built on principles of transparency, security, and efficiency
        </p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
        
        <!-- Highlight 1 -->
        <div class="relative text-center p-8 rounded-2xl bg-gradient-to-b from-slate-50 to-white hover:shadow-2xl transition-all duration-300 border-2 border-slate-100 hover:border-[#8B1E7E] group">
          <div class="absolute top-4 right-4">
            <span class="text-[10px] font-bold uppercase tracking-wider bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] text-white px-3 py-1 rounded-full">
              Secure
            </span>
          </div>
          <div class="inline-flex items-center justify-center w-16 h-16 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-[#006837] to-[#008a4a] text-white mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-emerald-500/30">
            <i data-lucide="shield" class="w-8 h-8 md:w-10 md:h-10"></i>
          </div>
          <h3 class="text-lg md:text-xl font-bold text-[#4A154B] mb-3">
            100% Confidentiality
          </h3>
          <p class="text-slate-600 text-sm leading-relaxed">
            Your identity and complaint details are protected with enterprise-grade security and encryption protocols
          </p>
          <div class="absolute bottom-0 left-1/2 transform -translate-x-1/2 w-12 h-1 bg-gradient-to-r from-[#4A154B] to-[#E5097F] rounded-full group-hover:w-full transition-all duration-300"></div>
        </div>

        <!-- Highlight 2 -->
        <div class="relative text-center p-8 rounded-2xl bg-gradient-to-b from-slate-50 to-white hover:shadow-2xl transition-all duration-300 border-2 border-slate-100 hover:border-[#8B1E7E] group">
          <div class="absolute top-4 right-4">
            <span class="text-[10px] font-bold uppercase tracking-wider bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] text-white px-3 py-1 rounded-full">
              Compliant
            </span>
          </div>
          <div class="inline-flex items-center justify-center w-16 h-16 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-[#006837] to-[#008a4a] text-white mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-emerald-500/30">
            <i data-lucide="check-circle" class="w-8 h-8 md:w-10 md:h-10"></i>
          </div>
          <h3 class="text-lg md:text-xl font-bold text-[#4A154B] mb-3">
            UGC Norms Compliant
          </h3>
          <p class="text-slate-600 text-sm leading-relaxed">
            Fully aligned with University Grants Commission guidelines and regulatory requirements
          </p>
          <div class="absolute bottom-0 left-1/2 transform -translate-x-1/2 w-12 h-1 bg-gradient-to-r from-[#4A154B] to-[#E5097F] rounded-full group-hover:w-full transition-all duration-300"></div>
        </div>

        <!-- Highlight 3 -->
        <div class="relative text-center p-8 rounded-2xl bg-gradient-to-b from-slate-50 to-white hover:shadow-2xl transition-all duration-300 border-2 border-slate-100 hover:border-[#8B1E7E] group">
          <div class="absolute top-4 right-4">
            <span class="text-[10px] font-bold uppercase tracking-wider bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] text-white px-3 py-1 rounded-full">
              Structured
            </span>
          </div>
          <div class="inline-flex items-center justify-center w-16 h-16 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-[#006837] to-[#008a4a] text-white mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-emerald-500/30">
            <i data-lucide="layers" class="w-8 h-8 md:w-10 md:h-10"></i>
          </div>
          <h3 class="text-lg md:text-xl font-bold text-[#4A154B] mb-3">
            Two-Tier Resolution System
          </h3>
          <p class="text-slate-600 text-sm leading-relaxed">
            Structured escalation process ensuring thorough investigation and fair resolution at every level
          </p>
          <div class="absolute bottom-0 left-1/2 transform -translate-x-1/2 w-12 h-1 bg-gradient-to-r from-[#4A154B] to-[#E5097F] rounded-full group-hover:w-full transition-all duration-300"></div>
        </div>

        <!-- Highlight 4 -->
        <div class="relative text-center p-8 rounded-2xl bg-gradient-to-b from-slate-50 to-white hover:shadow-2xl transition-all duration-300 border-2 border-slate-100 hover:border-[#8B1E7E] group">
          <div class="absolute top-4 right-4">
            <span class="text-[10px] font-bold uppercase tracking-wider bg-gradient-to-r from-[#4A154B] to-[#8B1E7E] text-white px-3 py-1 rounded-full">
              Timely
            </span>
          </div>
          <div class="inline-flex items-center justify-center w-16 h-16 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-[#006837] to-[#008a4a] text-white mb-6 group-hover:scale-110 transition-transform duration-300 shadow-lg shadow-emerald-500/30">
            <i data-lucide="clock" class="w-8 h-8 md:w-10 md:h-10"></i>
          </div>
          <h3 class="text-lg md:text-xl font-bold text-[#4A154B] mb-3">
            Strict SLA Resolution Timelines
          </h3>
          <p class="text-slate-600 text-sm leading-relaxed">
            Committed to timely resolution with transparent progress tracking and regular updates
          </p>
          <div class="absolute bottom-0 left-1/2 transform -translate-x-1/2 w-12 h-1 bg-gradient-to-r from-[#4A154B] to-[#E5097F] rounded-full group-hover:w-full transition-all duration-300"></div>
        </div>

      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer id="contact" class="bg-gradient-to-br from-[#4A154B] via-[#3a1040] to-[#2a0a30] text-white py-12 relative overflow-hidden scroll-mt-24">
    <div class="absolute top-0 right-0 w-96 h-96 bg-[#E5097F]/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 w-96 h-96 bg-[#006837]/10 rounded-full blur-3xl pointer-events-none"></div>
    
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
        <div>
          <div class="flex items-center space-x-3 mb-4">
            <img
              src="public/rcss-logo.png"
              alt="RCSS Logo"
              class="h-12 w-auto"
            />
            <img
              src="public/orel-grievance.png"
              alt="Oréll Grievance"
              class="h-8 w-auto"
            />
          </div>
          <p class="text-slate-300 text-sm leading-relaxed">
            Rajagiri College of Social Sciences - Committed to excellence in grievance redressal, powered by Oréll.
          </p>
        </div>
        
        <div>
          <h4 class="font-bold mb-4 text-[#C5A059]">Quick Links</h4>
          <ul class="space-y-2 text-sm">
            <li>
              <button 
                data-scroll-to="choose-portal" 
                class="text-slate-300 hover:text-[#E5097F] transition-colors text-left w-full flex items-center space-x-2 group cursor-pointer"
              >
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>File a Grievance</span>
              </button>
            </li>
            <li>
              <a href="login.php?role=student" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>Track Status</span>
              </a>
            </li>
            <li>
              <a href="#" class="text-slate-300 hover:text-[#E5097F] transition-colors flex items-center space-x-2 group">
                <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-1 transition-transform"></i>
                <span>UGC Guidelines</span>
              </a>
            </li>
          </ul>
        </div>
        
        <div>
          <h4 class="font-bold mb-4 text-[#C5A059]">Contact</h4>
          <ul class="space-y-3 text-sm text-slate-300">
            <li class="flex items-center space-x-2">
              <i data-lucide="mail" class="w-4 h-4 text-[#E5097F]"></i>
              <span>grievance@rajarigircss.edu</span>
            </li>
            <li class="flex items-center space-x-2">
              <i data-lucide="phone" class="w-4 h-4 text-[#E5097F]"></i>
              <span>+91 484 XXX XXXX</span>
            </li>
            <li class="flex items-center space-x-2">
              <i data-lucide="map-pin" class="w-4 h-4 text-[#E5097F]"></i>
              <span>Aluva, Kochi, Kerala</span>
            </li>
          </ul>
        </div>
      </div>
      
      <div class="border-t border-white/10 pt-8 text-center text-sm text-slate-400">
        <p>&copy; <?php echo date('Y'); ?> Rajagiri College of Social Sciences. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <!-- Page Scripts -->
  <script src="assets/js/index.js"></script>
</body>
</html>