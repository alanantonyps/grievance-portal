<?php
/**
 * management/grievance_reports.php
 * ---------------------------------------------------------------------------
 * Grievance Reports Navigation Page
 * Rajagiri College Grievance Redressal Portal
 *
 * Auth Check : role is MANAGEMENT or GRIEVANCE_MEMBER
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';
$allowedRoles = ['MANAGEMENT', 'GRIEVANCE_MEMBER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=management');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';
$conn   = null;

if (file_exists($dbFile)) {
    require_once $dbFile;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
    if ($conn->connect_error) {
        $conn = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
}

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// FETCH MEMBER PROFILE
// ---------------------------------------------------------------------------
$memberData = [
    'username'      => $_SESSION['username'] ?? 'Member',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
    'member_type'   => '',
    'has_cell_row'  => false,
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.username,
                        cm.name          AS name,
                        cm.email         AS email,
                        cm.profile_image AS profile_image,
                        cm.member_type   AS member_type
                FROM users u
                LEFT JOIN cell_members cm ON cm.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $memberData['username']      = $row['username']      ?? $memberData['username'];
                $memberData['name']          = $row['name']          ?? '';
                $memberData['email']         = $row['email']         ?? '';
                $memberData['profile_image'] = $row['profile_image'] ?? '';
                $memberData['member_type']   = $row['member_type']   ?? '';
                $memberData['has_cell_row']  = ($row['member_type'] !== null);
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Reports Member Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($memberData['name']) ? $memberData['name'] : $memberData['username'];
$displayEmail = !empty($memberData['email']) ? $memberData['email'] : 'management@rajagiri.edu';

// ---------------------------------------------------------------------------
// PROFILE PICTURE
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($memberData['profile_image'])) {
    $relative     = ltrim((string) $memberData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Reports | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

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
            brandGold:   '#C5A059',
            softPurple:  '#EAE3F7'
          },
          keyframes: {
            fadeInUp: {
              '0%':   { opacity: '0', transform: 'translateY(12px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            dropdownFade: {
              '0%':   { opacity: '0', transform: 'translateY(-8px) scale(0.98)' },
              '100%': { opacity: '1', transform: 'translateY(0) scale(1)' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside id="managementSidebar"
           class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837]
                  flex flex-col py-4 shadow-2xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <button id="sidebarToggle"
              class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors
                     flex items-center justify-center w-14 mx-auto"
              aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
      </button>

      <nav class="flex flex-col space-y-2 flex-1 w-full px-3">

        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            Dashboard
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            My Profile
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            My Profile
          </span>
        </a>

        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            Change Password
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Change Password
          </span>
        </a>

      </nav>

      <a href="#"
         data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-red-500/40
                flex items-center text-white transition-all
                mx-3 px-3"
         style="width: calc(100% - 1.5rem);"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                     opacity-0 w-0 overflow-hidden transition-all duration-200">
          Logout
        </span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                     bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>

    </aside>

    <!-- MAIN CONTENT -->
    <div id="managementMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <!-- TOP HEADER -->
      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30">
        <div class="flex items-center justify-between px-6 py-4">

          <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center group">
              <img src="../public/rcss-logo.png" alt="RCSS Logo"
                   class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105" />
            </a>

            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>

            <img src="../public/orel-grievance.png" alt="Oréll Grievance"
                 class="hidden sm:block h-8 md:h-9 w-auto object-contain" />
          </div>

          <div class="relative" id="management-dropdown-container">
            <button id="management-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-slate-100 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                            flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-slate-700">
                <?= e($displayName) ?>
              </span>
              <i data-lucide="chevron-down" id="management-chevron"
                 class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="management-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl
                        border border-slate-200 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                                flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700
                        hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50
                        hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="layout-dashboard"
                   class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right"
                   class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="profile.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700
                        hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50
                        hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="user"
                   class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right"
                   class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="change_password.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700
                        hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50
                        hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="key"
                   class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right"
                   class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600
                          hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <!-- PAGE CONTENT -->
      <main class="flex-1 px-6 py-8">

        <!-- Header Section -->
        <div class="max-w-5xl mx-auto text-center mb-12 animate-fade-in-up">

          <h1 class="text-3xl md:text-4xl font-bold text-slate-800 mb-4 tracking-tight">
            Rajagiri College of Social Sciences
          </h1>

          <nav class="flex items-center justify-center space-x-2 text-sm text-slate-600">
            <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors font-medium">
              <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1.5"></i>
              Dashboard
            </a>
            <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
            <span class="text-[#E5097F] font-semibold">Grievance Reports</span>
          </nav>

        </div>

        <!-- Report Cards Grid -->
        <div class="max-w-5xl mx-auto animate-fade-in-up" style="animation-delay: 80ms;">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-12">

            <!-- CARD 1: Complaint Report -->
            <a href="complaint_report.php"
               class="group flex items-center gap-6 p-6 rounded-2xl bg-white border border-slate-200/70
                      shadow-md hover:shadow-2xl hover:-translate-y-1
                      transition-all duration-300 ease-out">

              <!-- Icon container -->
              <div class="relative flex-shrink-0">
                <div class="w-28 h-28 md:w-32 md:h-32 rounded-2xl bg-[#EAE3F7]
                            flex items-center justify-center
                            group-hover:scale-105 transition-transform duration-300">
                  <!-- Illustration: documents + magnifier + person -->
                  <svg viewBox="0 0 120 120" class="w-20 h-20 md:w-24 md:h-24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Back document -->
                    <rect x="34" y="22" width="46" height="62" rx="4" fill="#FFFFFF" stroke="#B8A9D9" stroke-width="2"/>
                    <!-- Lines on back document -->
                    <rect x="42" y="32" width="30" height="3" rx="1.5" fill="#D9CFEF"/>
                    <rect x="42" y="40" width="24" height="3" rx="1.5" fill="#D9CFEF"/>
                    <rect x="42" y="48" width="28" height="3" rx="1.5" fill="#D9CFEF"/>
                    <rect x="42" y="56" width="20" height="3" rx="1.5" fill="#D9CFEF"/>
                    <!-- Checkmark -->
                    <circle cx="66" cy="70" r="7" fill="#7ED0B1"/>
                    <path d="M63 70l2.5 2.5L70 67" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>

                    <!-- Front document -->
                    <rect x="46" y="30" width="46" height="62" rx="4" fill="#FFFFFF" stroke="#8B7BC0" stroke-width="2"/>
                    <rect x="54" y="40" width="30" height="3" rx="1.5" fill="#C5B7E0"/>
                    <rect x="54" y="48" width="24" height="3" rx="1.5" fill="#C5B7E0"/>
                    <rect x="54" y="56" width="28" height="3" rx="1.5" fill="#C5B7E0"/>

                    <!-- Magnifying glass -->
                    <circle cx="72" cy="76" r="11" fill="#FFFFFF" stroke="#4A154B" stroke-width="2.5"/>
                    <circle cx="72" cy="76" r="7" fill="#EAE3F7" opacity="0.6"/>
                    <line x1="80" y1="84" x2="88" y2="92" stroke="#4A154B" stroke-width="3" stroke-linecap="round"/>

                    <!-- Person -->
                    <circle cx="28" cy="72" r="6" fill="#F5D6B0"/>
                    <path d="M18 96c0-6 5-10 10-10s10 4 10 10" fill="#7ED0B1"/>
                    <rect x="22" y="60" width="12" height="10" rx="3" fill="#4A154B" opacity="0.85"/>

                    <!-- Small papers near person -->
                    <rect x="14" y="82" width="18" height="10" rx="2" fill="#FFFFFF" stroke="#B8A9D9" stroke-width="1.5" transform="rotate(-12 23 87)"/>
                    <rect x="24" y="86" width="16" height="9" rx="2" fill="#FFFFFF" stroke="#B8A9D9" stroke-width="1.5" transform="rotate(8 32 90)"/>
                  </svg>
                </div>

                <!-- Star badge -->
                <div class="absolute -top-1 -right-1 w-7 h-7 rounded-full bg-white shadow-md
                            flex items-center justify-center">
                  <i data-lucide="star" class="w-4 h-4 text-[#B8A9D9] fill-[#B8A9D9]"></i>
                </div>
              </div>

              <!-- Text content -->
              <div class="min-w-0 flex-1">
                <h3 class="text-lg md:text-xl font-bold text-slate-800 mb-2
                           group-hover:text-[#4A154B] transition-colors">
                  Complaint Report
                </h3>
                <p class="text-sm md:text-base font-semibold text-[#E5097F]
                          group-hover:underline transition-all">
                  click here to open
                </p>
              </div>

            </a>

            <!-- CARD 2: Cell Members Report -->
            <a href="cell_members_report.php"
               class="group flex items-center gap-6 p-6 rounded-2xl bg-white border border-slate-200/70
                      shadow-md hover:shadow-2xl hover:-translate-y-1
                      transition-all duration-300 ease-out">

              <!-- Icon container -->
              <div class="relative flex-shrink-0">
                <div class="w-28 h-28 md:w-32 md:h-32 rounded-2xl bg-[#EAE3F7]
                            flex items-center justify-center
                            group-hover:scale-105 transition-transform duration-300">
                  <!-- Illustration: clipboard with checklist -->
                  <svg viewBox="0 0 120 120" class="w-20 h-20 md:w-24 md:h-24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Clipboard body -->
                    <rect x="30" y="22" width="60" height="80" rx="6" fill="#FFFFFF" stroke="#8B7BC0" stroke-width="2"/>
                    <!-- Clipboard top bar -->
                    <rect x="30" y="22" width="60" height="14" rx="6" fill="#D9CFEF"/>
                    <rect x="30" y="30" width="60" height="6" fill="#D9CFEF"/>
                    <!-- Clipboard clip -->
                    <rect x="48" y="16" width="24" height="12" rx="3" fill="#B8A9D9" stroke="#8B7BC0" stroke-width="1.5"/>
                    <circle cx="60" cy="22" r="2" fill="#FFFFFF"/>

                    <!-- Checkbox rows -->
                    <g transform="translate(38, 48)">
                      <!-- Row 1 -->
                      <rect x="0" y="0" width="10" height="10" rx="2" fill="#F0FDF4" stroke="#4ADE80" stroke-width="1.5"/>
                      <path d="M2.5 5l2 2 3.5-4" stroke="#16A34A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <rect x="16" y="2" width="26" height="3" rx="1.5" fill="#E5E5E5"/>
                      <rect x="16" y="7" width="20" height="2.5" rx="1.25" fill="#F0F0F0"/>

                      <!-- Row 2 -->
                      <rect x="0" y="16" width="10" height="10" rx="2" fill="#F0FDF4" stroke="#4ADE80" stroke-width="1.5"/>
                      <path d="M2.5 21l2 2 3.5-4" stroke="#16A34A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <rect x="16" y="18" width="28" height="3" rx="1.5" fill="#E5E5E5"/>
                      <rect x="16" y="23" width="22" height="2.5" rx="1.25" fill="#F0F0F0"/>

                      <!-- Row 3 -->
                      <rect x="0" y="32" width="10" height="10" rx="2" fill="#F0FDF4" stroke="#4ADE80" stroke-width="1.5"/>
                      <path d="M2.5 37l2 2 3.5-4" stroke="#16A34A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <rect x="16" y="34" width="24" height="3" rx="1.5" fill="#E5E5E5"/>
                      <rect x="16" y="39" width="18" height="2.5" rx="1.25" fill="#F0F0F0"/>

                      <!-- Row 4 -->
                      <rect x="0" y="48" width="10" height="10" rx="2" fill="#F0FDF4" stroke="#4ADE80" stroke-width="1.5"/>
                      <path d="M2.5 53l2 2 3.5-4" stroke="#16A34A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                      <rect x="16" y="50" width="26" height="3" rx="1.5" fill="#E5E5E5"/>
                      <rect x="16" y="55" width="20" height="2.5" rx="1.25" fill="#F0F0F0"/>
                    </g>
                  </svg>
                </div>

                <!-- Star badge -->
                <div class="absolute -top-1 -right-1 w-7 h-7 rounded-full bg-white shadow-md
                            flex items-center justify-center">
                  <i data-lucide="star" class="w-4 h-4 text-[#B8A9D9] fill-[#B8A9D9]"></i>
                </div>
              </div>

              <!-- Text content -->
              <div class="min-w-0 flex-1">
                <h3 class="text-lg md:text-xl font-bold text-slate-800 mb-2
                           group-hover:text-[#4A154B] transition-colors">
                  Cell Members Report
                </h3>
                <p class="text-sm md:text-base font-semibold text-[#E5097F]
                          group-hover:underline transition-all">
                  click here to open
                </p>
              </div>

            </a>

          </div>

        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-[#EAE3F7] border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center space-y-1">
            <p class="text-sm text-slate-800">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold">Rajagiri College of Social Sciences</span> .
              All rights reserved.
            </p>
            <p class="text-sm text-slate-800">
              Powered by
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">
                Orell
              </span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- LOGOUT CONFIRMATION MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[90] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Log Out?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to log out of
          <span class="font-bold text-[#8B1E7E] break-words"><?= e($displayName) ?></span>.
        </p>

        <p class="text-xs text-slate-400 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i>
          You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="button"
                id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-red-500 via-red-600 to-rose-600
                       hover:from-red-600 hover:via-red-700 hover:to-rose-700
                       shadow-lg shadow-red-500/30 hover:shadow-red-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>

    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    /* ---------------- Sidebar toggle ---------------- */
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('managementSidebar');
      const main      = document.getElementById('managementMain');
      if (!toggleBtn || !sidebar || !main) return;

      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');

      let expanded = false;

      toggleBtn.addEventListener('click', function () {
        expanded = !expanded;

        if (expanded) {
          sidebar.classList.remove('w-20');
          sidebar.classList.add('w-64');
          main.classList.remove('ml-20');
          main.classList.add('ml-64');

          labels.forEach(function (el) {
            el.classList.remove('opacity-0', 'w-0');
            el.classList.add('opacity-100', 'w-auto');
          });
          tooltips.forEach(function (el) { el.classList.add('hidden'); });
        } else {
          sidebar.classList.add('w-20');
          sidebar.classList.remove('w-64');
          main.classList.add('ml-20');
          main.classList.remove('ml-64');

          labels.forEach(function (el) {
            el.classList.add('opacity-0', 'w-0');
            el.classList.remove('opacity-100', 'w-auto');
          });
          tooltips.forEach(function (el) { el.classList.remove('hidden'); });
        }

        setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
      });
    })();

    /* ---------------- Profile dropdown ---------------- */
    (function () {
      const btn       = document.getElementById('management-dropdown-btn');
      const menu      = document.getElementById('management-dropdown-menu');
      const chevron   = document.getElementById('management-chevron');
      const container = document.getElementById('management-dropdown-container');

      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    /* ---------------- Logout ---------------- */
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=management';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeLogoutModal() {
      logoutConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    (function () {
      const triggers = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];
      triggers.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          openLogoutModal();
        });
      });
    })();

    if (confirmLogoutBtn) {
      confirmLogoutBtn.addEventListener('click', function () {
        confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
        window.location.href = LOGOUT_URL;
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) {
        closeLogoutModal();
      }
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>