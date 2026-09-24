<?php
/**
 * management/dashboard.php
 * ---------------------------------------------------------------------------
 * Management Dashboard — Rajagiri College Grievance Redressal Portal
 *
 * Auth Check : case-insensitive role match against MANAGEMENT
 *              (also accepts GRIEVANCE_MEMBER for legacy compatibility)
 * Profile    : Fetches name + profile_image from cell_members via LEFT JOIN
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

$allowedRoles = ['MANAGEMENT', 'GRIEVANCE_MEMBER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=management');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

$dbError = null;
$conn    = null;

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) not found.';
} else {
    require_once $dbFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
        if ($conn->connect_error) {
            $dbError = 'Database connection failed.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed.';
        $conn    = null;
    }
}

// ---------------------------------------------------------------------------
// 4. FETCH MEMBER PROFILE (users LEFT JOIN cell_members)
// ---------------------------------------------------------------------------
$memberData = [
    'username'      => 'Member',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
    'member_type'   => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        u.role,
                        cm.name            AS name,
                        cm.email           AS email,
                        cm.profile_image   AS profile_image,
                        cm.member_type     AS member_type
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
                $memberData['username']      = $row['username']      ?? 'Member';
                $memberData['name']          = $row['name']          ?? '';
                $memberData['email']         = $row['email']         ?? '';
                $memberData['profile_image'] = $row['profile_image'] ?? '';
                $memberData['member_type']   = $row['member_type']   ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Management Dashboard Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($memberData['name']) ? $memberData['name'] : $memberData['username'];
$displayEmail = !empty($memberData['email']) ? $memberData['email'] : 'management@rajagiri.edu';

// ---------------------------------------------------------------------------
// 5. PROFILE PICTURE RESOLUTION
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

// ---------------------------------------------------------------------------
// 6. NAVIGATION CARDS CONFIG
// ---------------------------------------------------------------------------
$navCards = [
    ['title' => 'Grievance',         'icon' => 'clipboard-list', 'href' => 'grievances.php'],
    ['title' => 'Grievance Reports', 'icon' => 'bar-chart-3',    'href' => 'grievance_reports.php'],
];

// ---------------------------------------------------------------------------
// 7. HELPER
// ---------------------------------------------------------------------------
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
  <title>Management Dashboard — Rajagiri College Grievance Portal</title>
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
            brandGold:   '#C5A059'
          },
          keyframes: {
            fadeInUp: {
              '0%':   { opacity: '0', transform: 'translateY(12px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            dropdownFade: {
              '0%':   { opacity: '0', transform: 'translateY(-8px) scale(0.98)' },
              '100%': { opacity: '1', transform: 'translateY(0) scale(1)' }
            },
            modalFadeIn: {
              '0%':   { opacity: '0', transform: 'scale(0.96)' },
              '100%': { opacity: '1', transform: 'scale(1)' }
            },
            confirmShake: {
              '0%, 100%': { transform: 'translateX(0)' },
              '20%':      { transform: 'translateX(-6px)' },
              '40%':      { transform: 'translateX(6px)' },
              '60%':      { transform: 'translateX(-4px)' },
              '80%':      { transform: 'translateX(4px)' }
            },
            submenuFade: {
              '0%':   { opacity: '0', maxHeight: '0' },
              '100%': { opacity: '1', maxHeight: '500px' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'submenu':    'submenuFade 0.25s ease-out forwards'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR (collapsible) -->
    <aside id="managementSidebar"
           class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837]
                  flex flex-col py-4 shadow-2xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <button id="sidebarToggle"
              class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors
                     flex items-center justify-center w-14 mx-auto flex-shrink-0"
              aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
      </button>

      <!-- Scrollable nav -->
      <nav id="managementNav"
           class="flex flex-col space-y-2 flex-1 w-full px-3 pt-2 overflow-y-auto overflow-x-hidden
                  [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,0.2)_transparent]">

        <!-- Dashboard -->
        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl bg-white/20 backdrop-blur-sm
                  flex items-center text-white shadow-lg ring-2 ring-white/30
                  transition-all hover:bg-white/30 px-3 flex-shrink-0">
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

        <!-- Grievance (direct link) -->
        <a href="grievances.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="clipboard-list" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                       opacity-0 w-0 overflow-hidden transition-all duration-200">
            Grievance
          </span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                       bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Grievance
          </span>
        </a>

        <!-- Grievance Reports (dropdown group) -->
        <div class="sidebar-group flex-shrink-0" data-section="reports">
          <!-- Parent button — toggles submenu only when sidebar expanded;
               navigates to landing page when collapsed -->
          <button type="button"
                  id="reportsToggle"
                  data-submenu-toggle="reports"
                  data-landing-href="grievance_reports.php"
                  class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                         flex items-center text-white transition-all px-3">
            <i data-lucide="bar-chart-3" class="w-6 h-6 flex-shrink-0"></i>
            <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap
                         opacity-0 w-0 overflow-hidden transition-all duration-200">
              Grievance Reports
            </span>
            <i data-lucide="chevron-down"
               class="sidebar-label submenu-chevron ml-auto w-4 h-4 flex-shrink-0
                      transition-transform duration-300 opacity-0 w-0 overflow-hidden"></i>
            <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap
                         bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
              Grievance Reports
            </span>
          </button>

          <!-- Submenu -->
          <div id="submenu-reports"
               class="submenu hidden ml-2 mt-1 space-y-1 pl-3 border-l border-white/20">
            <a href="complaint_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white
                      hover:bg-white/10 transition-all text-xs">
              <i data-lucide="file-bar-chart" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Complaint Report</span>
            </a>
            <a href="cell_members_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white
                      hover:bg-white/10 transition-all text-xs">
              <i data-lucide="users-2" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Cell Member Report</span>
            </a>
          </div>
        </div>

        <!-- My Profile -->
        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3 flex-shrink-0">
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

        <!-- Change Password -->
        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20
                  flex items-center text-white transition-all px-3 flex-shrink-0">
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

      <!-- Logout -->
      <a href="#"
         data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-red-500/40
                flex items-center text-white transition-all
                mx-3 px-3 flex-shrink-0"
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

    <!-- MAIN CONTENT WRAPPER -->
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

          <!-- Profile dropdown -->
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
      <main class="flex-1 px-6 py-10">

        <?php if ($dbError): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- Institution Title -->
        <div class="text-center mb-12 animate-fade-in-up">
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-slate-800 mb-3 tracking-tight">
            Rajagiri College of Social Sciences
          </h1>
          <h2 class="text-lg md:text-xl lg:text-2xl font-semibold text-slate-600">
            Management Dashboard
          </h2>
        </div>

        <!-- NAVIGATION CARDS -->
        <div class="flex flex-wrap items-center justify-center gap-6 md:gap-10 max-w-4xl mx-auto animate-fade-in-up" style="animation-delay: 80ms;">

          <?php foreach ($navCards as $card): ?>
            <a href="<?= e($card['href']) ?>"
               class="group relative w-64 h-64 md:w-72 md:h-72 bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F]
                      rounded-3xl shadow-xl hover:shadow-2xl
                      flex flex-col items-center justify-center text-white text-center
                      transition-all duration-300 hover:scale-[1.03] hover:-translate-y-1 overflow-hidden">

              <div class="absolute -top-10 -right-10 w-32 h-32 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
              <div class="absolute -bottom-12 -left-12 w-36 h-36 bg-white/5 rounded-full group-hover:scale-150 transition-transform duration-500"></div>

              <div class="relative flex flex-col items-center">
                <div class="w-24 h-24 md:w-28 md:h-28 rounded-3xl bg-white/20 backdrop-blur-sm
                            flex items-center justify-center mb-6
                            group-hover:bg-white/30 group-hover:scale-110 transition-all duration-300">
                  <i data-lucide="<?= e($card['icon']) ?>" class="w-12 h-12 md:w-14 md:h-14"></i>
                </div>

                <p class="text-xl md:text-2xl font-bold tracking-wide leading-tight px-4">
                  <?= e($card['title']) ?>
                </p>
              </div>
            </a>
          <?php endforeach; ?>

        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs md:text-sm text-slate-800">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
              <span class="ml-1">All rights reserved.</span>
            </p>
            <p class="text-xs md:text-sm text-slate-700 mt-1">
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

  <!-- ============================================================ -->
  <!-- CUSTOM LOGOUT CONFIRMATION MODAL                              -->
  <!-- ============================================================ -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

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
          Any unsaved changes will be lost.
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

    // ============================================================
    // SIDEBAR EXPAND / COLLAPSE
    // ============================================================
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('managementSidebar');
      const main      = document.getElementById('managementMain');
      if (!toggleBtn || !sidebar || !main) return;

      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
      const chevrons = sidebar.querySelectorAll('.submenu-chevron');
      const submenus = sidebar.querySelectorAll('.submenu');

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

          // Auto-collapse any open submenus when the sidebar is collapsed
          submenus.forEach(function (sm) { sm.classList.add('hidden'); });
          chevrons.forEach(function (ch) { ch.classList.remove('rotate-180'); });
        }

        setTimeout(function () {
          if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 250);
      });
    })();

    // ============================================================
    // SUBMENU TOGGLE (Grievance Reports)
    //   - Collapsed sidebar → navigate to landing page
    //   - Expanded sidebar  → toggle submenu
    // ============================================================
    (function () {
      const toggles = document.querySelectorAll('[data-submenu-toggle]');
      if (!toggles.length) return;

      toggles.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          const sidebarEl = document.getElementById('managementSidebar');
          const isCollapsed = sidebarEl && sidebarEl.classList.contains('w-20');

          // COLLAPSED → navigate to landing page
          if (isCollapsed) {
            const href = btn.getAttribute('data-landing-href');
            if (href) window.location.href = href;
            return;
          }

          // EXPANDED → toggle submenu
          e.preventDefault();
          e.stopPropagation();

          const sectionId = btn.getAttribute('data-submenu-toggle');
          const submenu   = document.getElementById('submenu-' + sectionId);
          const chevron   = btn.querySelector('.submenu-chevron');

          if (!submenu) return;

          const isOpen = !submenu.classList.contains('hidden');

          // Accordion: close other submenus
          document.querySelectorAll('.submenu').forEach(function (sm) {
            if (sm !== submenu) sm.classList.add('hidden');
          });
          document.querySelectorAll('.submenu-chevron').forEach(function (ch) {
            if (ch !== chevron) ch.classList.remove('rotate-180');
          });

          if (isOpen) {
            submenu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
          } else {
            submenu.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
          }
        });
      });
    })();

    // ============================================================
    // PROFILE DROPDOWN
    // ============================================================
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
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden');
          menu.classList.add('animate-dropdown');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          menu.classList.remove('animate-dropdown');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    // ============================================================
    // LOGOUT CONFIRMATION MODAL
    // ============================================================
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');

    const LOGOUT_URL = '../logout.php?role=management';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (logoutConfirmPanel) {
        logoutConfirmPanel.classList.remove('animate-confirm-shake');
        void logoutConfirmPanel.offsetWidth;
        logoutConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
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