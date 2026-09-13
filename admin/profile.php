<?php
/**
 * admin/profile.php
 * ---------------------------------------------------------------------------
 * Admin Profile (View Only) — Rajagiri College Grievance Redressal Portal
 *
 * Auth Check : case-insensitive role match against 'ADMIN'
 * Data Source: users LEFT JOIN admin_profiles
 * Fields     : Name, Address, Email, Contact Number
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

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

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

// ---------------------------------------------------------------------------
// 4. FETCH ADMIN PROFILE DATA (LEFT JOIN)
// ---------------------------------------------------------------------------
$profile = [
    'username'        => 'Admin',
    'name'            => '',
    'address'         => '',
    'email'           => '',
    'mobile_number'   => '',
    'profile_picture' => '',
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.id,
                        u.username,
                        u.role,
                        u.status,
                        ap.name            AS name,
                        ap.address         AS address,
                        ap.email           AS email,
                        ap.mobile_number   AS mobile_number,
                        ap.profile_picture AS profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $profile['username']        = $row['username']        ?? 'Admin';
                $profile['name']            = $row['name']            ?? '';
                $profile['address']         = $row['address']         ?? '';
                $profile['email']           = $row['email']           ?? '';
                $profile['mobile_number']   = $row['mobile_number']   ?? '';
                $profile['profile_picture'] = $row['profile_picture'] ?? '';
            }

            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Admin Profile Fetch] ' . $ex->getMessage());
        $dbError = 'Unable to load profile data at this time.';
    }
}

// ---------------------------------------------------------------------------
// 5. FALLBACK LOGIC FOR DISPLAY NAME
// ---------------------------------------------------------------------------
$displayName = !empty($profile['name'])
    ? $profile['name']
    : $profile['username'];

// Safe display values (fallback to "Not provided" if empty)
$displayAddress = !empty($profile['address'])       ? $profile['address']       : 'Not provided';
$displayEmail   = !empty($profile['email'])         ? $profile['email']         : 'Not provided';
$displayMobile  = !empty($profile['mobile_number']) ? $profile['mobile_number'] : 'Not provided';

// ---------------------------------------------------------------------------
// 6. PROFILE PICTURE RESOLUTION
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($profile['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $profile['profile_picture'], '/');

    if (file_exists(__DIR__ . '/../' . ltrim((string) $profile['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// ---------------------------------------------------------------------------
// 7. INITIALS (used in the fallback avatar)
// ---------------------------------------------------------------------------
$initials = 'A';
if (!empty($displayName)) {
    $parts = preg_split('/[\s._-]+/', trim((string) $displayName));
    if (is_array($parts) && count($parts) >= 2) {
        $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    } else {
        $initials = strtoupper(substr((string) $displayName, 0, 2));
    }
}

// ---------------------------------------------------------------------------
// 8. HELPER
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
  <title>User Details — Admin | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Tailwind Theme -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059'
          },
          keyframes: {
            fadeInUp: {
              '0%':   { opacity: '0', transform: 'translateY(12px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            softFloat: {
              '0%, 100%': { transform: 'translateY(0px)' },
              '50%':      { transform: 'translateY(-4px)' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'soft-float': 'softFloat 4s ease-in-out infinite'
          }
        }
      }
    };
  </script>

  <!-- Local Styles -->
  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40">

      <!-- Toggle Icon -->
      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <!-- Nav Icons -->
      <nav class="flex flex-col items-center space-y-6 flex-1">

        <!-- Dashboard -->
        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <!-- Profile (active) -->
        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-white shadow-lg ring-2 ring-white/30 transition-all hover:scale-110 hover:bg-white/30"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Profile
          </span>
        </a>

      </nav>

      <!-- Logout -->
      <a href="../logout.php?role=admin"
         id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>

    </aside>

    <!-- ============================================================
         MAIN CONTENT WRAPPER
         ============================================================ -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- ============ TOP HEADER ============ -->
      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30">
        <div class="flex items-center justify-between px-6 py-4">

          <!-- Left: Logos -->
          <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center group">
              <img
                src="../public/rcss-logo.png"
                alt="RCSS Logo"
                class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105"
              />
            </a>

            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>

            <img
              src="../public/orel-grievance.png"
              alt="Oréll Grievance"
              class="hidden sm:block h-8 md:h-9 w-auto object-contain"
            />
          </div>

          <!-- Right: Admin Profile Dropdown -->
          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-slate-100 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img
                  src="<?= e($profilePictureUrl) ?>"
                  alt="Admin Profile"
                  class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100"
                />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-slate-700">
                <?= e($displayName) ?>
              </span>

              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img
                      src="<?= e($profilePictureUrl) ?>"
                      alt="Admin Profile"
                      class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]"
                    />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>

                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="profile.php"
                 class="flex items-center px-4 py-2.5 text-sm text-[#8B1E7E] bg-purple-50/50 font-medium">
                <i data-lucide="user" class="w-4 h-4 mr-3"></i>
                <span>My Profile</span>
              </a>

              <a href="change_password.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="../logout.php?role=admin"
                   id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <!-- ============ PAGE CONTENT ============ -->
      <main class="flex-1 px-6 py-8">

        <!-- Breadcrumb -->
        <div class="max-w-5xl mx-auto mb-8 animate-fade-in-up">
          <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-3 flex items-center tracking-tight">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#4A154B] to-[#E5097F] flex items-center justify-center mr-3 shadow-lg shadow-purple-500/20">
              <i data-lucide="user" class="w-5 h-5 text-white"></i>
            </div>
            User Details
          </h1>
          <nav class="flex items-center space-x-2 text-sm text-slate-500 ml-1">
            <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
              Dashboard
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-[#E5097F] font-semibold">User Details</span>
          </nav>
        </div>

        <!-- DB Error Notice -->
        <?php if ($dbError): ?>
          <div class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- ============ PROFILE CARD ============ -->
        <div class="max-w-3xl mx-auto">

          <div class="relative group/card animate-fade-in-up" style="animation-delay: 100ms;">
            <!-- Card Glow -->
            <div class="absolute -inset-0.5 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-3xl blur opacity-10 group-hover/card:opacity-30 transition duration-500"></div>

            <div class="relative bg-white rounded-3xl shadow-xl border border-slate-200/60 overflow-hidden transition-shadow duration-300 group-hover/card:shadow-2xl">

              <!-- ============ CARD HEADER ============ -->
              <div class="bg-gradient-to-r from-slate-50 to-slate-100 px-6 py-4 border-b border-slate-200">
                <h2 class="text-lg md:text-xl font-bold text-slate-800 flex items-center">
                  <i data-lucide="id-card" class="w-5 h-5 mr-2 text-[#8B1E7E]"></i>
                  User Details
                </h2>
              </div>

              <!-- ============ AVATAR BANNER ============ -->
              <div class="relative px-6 py-12 overflow-hidden">
                <!-- Layered gradient background -->
                <div class="absolute inset-0 bg-gradient-to-br from-pink-100 via-purple-50 to-pink-50"></div>

                <!-- Decorative shapes -->
                <div class="absolute -top-10 -right-10 w-40 h-40 bg-gradient-to-br from-[#E5097F]/10 to-transparent rounded-full blur-2xl"></div>
                <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-gradient-to-br from-[#4A154B]/10 to-transparent rounded-full blur-2xl"></div>
                <div class="absolute top-4 right-8 w-16 h-16 bg-[#C5A059]/10 rounded-full blur-xl"></div>

                <div class="relative flex flex-col items-center justify-center">

                  <!-- Avatar -->
                  <div class="relative animate-soft-float">
                    <!-- Glow ring -->
                    <div class="absolute -inset-2 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-full blur-md opacity-40"></div>

                    <!-- Outer ring -->
                    <div class="relative w-32 h-32 md:w-36 md:h-36 rounded-full bg-white p-1.5 shadow-2xl">
                      <div class="w-full h-full rounded-full overflow-hidden ring-4 ring-white">
                        <?php if ($hasProfilePicture): ?>
                          <img
                            src="<?= e($profilePictureUrl) ?>"
                            alt="<?= e($displayName) ?>"
                            class="w-full h-full object-cover"
                          />
                        <?php else: ?>
                          <div class="w-full h-full bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] flex items-center justify-center text-white">
                            <i data-lucide="user" class="w-14 h-14 md:w-16 md:h-16 text-white"></i>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <!-- Name -->
                  <h3 class="mt-5 text-2xl md:text-3xl font-bold text-slate-800 tracking-tight">
                    <?= e($displayName) ?>
                  </h3>

                  <!-- Role chip -->
                  <div class="mt-2 inline-flex items-center space-x-1.5 bg-white/80 backdrop-blur-sm border border-purple-200 px-3 py-1 rounded-full shadow-sm">
                    <i data-lucide="shield-check" class="w-3.5 h-3.5 text-[#8B1E7E]"></i>
                    <span class="text-xs font-bold text-[#4A154B] uppercase tracking-wider">Administrator</span>
                  </div>
                </div>
              </div>

              <!-- ============ INFO TABLE (4 Rows) ============ -->
              <div class="px-6 md:px-10 py-8 bg-slate-50/30 border-t border-slate-100">
                <div class="flex items-center mb-5">
                  <i data-lucide="clipboard-list" class="w-4 h-4 text-[#8B1E7E] mr-2"></i>
                  <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Personal Information</h4>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                  <table class="w-full">
                    <tbody class="divide-y divide-slate-100">

                      <!-- Row 1: Name -->
                      <tr class="group/row hover:bg-gradient-to-r hover:from-pink-50/40 hover:to-purple-50/40 transition-all duration-200">
                        <td class="w-1/3 px-5 py-5 align-top">
                          <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-[#8B1E7E] transition-colors">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-pink-100 to-purple-100 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                              <i data-lucide="user" class="w-4 h-4 text-[#8B1E7E]"></i>
                            </div>
                            Name
                          </div>
                        </td>
                        <td class="px-5 py-5 align-top">
                          <p class="text-slate-800 font-semibold text-base">
                            <?= e($displayName) ?>
                          </p>
                        </td>
                      </tr>

                      <!-- Row 2: Address -->
                      <tr class="group/row hover:bg-gradient-to-r hover:from-pink-50/40 hover:to-purple-50/40 transition-all duration-200">
                        <td class="w-1/3 px-5 py-5 align-top">
                          <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-[#8B1E7E] transition-colors">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-pink-100 to-purple-100 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                              <i data-lucide="map-pin" class="w-4 h-4 text-[#8B1E7E]"></i>
                            </div>
                            Address
                          </div>
                        </td>
                        <td class="px-5 py-5 align-top">
                          <p class="text-slate-700 text-base leading-relaxed <?= $displayAddress === 'Not provided' ? 'italic text-slate-400' : '' ?>">
                            <?= e($displayAddress) ?>
                          </p>
                        </td>
                      </tr>

                      <!-- Row 3: Email -->
                      <tr class="group/row hover:bg-gradient-to-r hover:from-pink-50/40 hover:to-purple-50/40 transition-all duration-200">
                        <td class="w-1/3 px-5 py-5 align-top">
                          <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-[#8B1E7E] transition-colors">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-pink-100 to-purple-100 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                              <i data-lucide="mail" class="w-4 h-4 text-[#8B1E7E]"></i>
                            </div>
                            Email
                          </div>
                        </td>
                        <td class="px-5 py-5 align-top">
                          <p class="text-slate-700 text-base break-all <?= $displayEmail === 'Not provided' ? 'italic text-slate-400' : '' ?>">
                            <?= e($displayEmail) ?>
                          </p>
                        </td>
                      </tr>

                      <!-- Row 4: Contact Number -->
                      <tr class="group/row hover:bg-gradient-to-r hover:from-pink-50/40 hover:to-purple-50/40 transition-all duration-200">
                        <td class="w-1/3 px-5 py-5 align-top">
                          <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-[#8B1E7E] transition-colors">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-pink-100 to-purple-100 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                              <i data-lucide="phone" class="w-4 h-4 text-[#8B1E7E]"></i>
                            </div>
                            Contact Number
                          </div>
                        </td>
                        <td class="px-5 py-5 align-top">
                          <p class="text-slate-700 text-base <?= $displayMobile === 'Not provided' ? 'italic text-slate-400' : '' ?>">
                            <?= e($displayMobile) ?>
                          </p>
                        </td>
                      </tr>

                    </tbody>
                  </table>
                </div>
              </div>

              <!-- ============ ACTION FOOTER ============ -->
              <div class="px-6 md:px-10 py-6 bg-white border-t border-slate-200 flex flex-col sm:flex-row justify-between items-center gap-3">

                <!-- Back to Dashboard Button — Soft Neutral Outline -->
                <a href="dashboard.php"
                   class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl border-2 border-slate-200 bg-white hover:border-[#8B1E7E]/40 hover:bg-slate-50 transition-all duration-300 hover:scale-[1.02] active:scale-95">
                  <div class="relative flex items-center justify-center space-x-2 py-2.5 px-6 text-slate-700 font-bold group-hover/btn:text-[#8B1E7E] transition-colors">
                    <i data-lucide="arrow-left" class="w-4 h-4 group-hover/btn:-translate-x-1 transition-transform duration-300"></i>
                    <span>Back to Dashboard</span>
                  </div>
                </a>

                <!-- Edit Button — Darker Purple Gradient -->
                <a href="edit_profile.php"
                   class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl shadow-lg shadow-purple-500/30 hover:shadow-2xl hover:shadow-pink-500/40 transition-all duration-300 hover:scale-[1.03] active:scale-95">

                  <!-- Darker on-theme gradient -->
                  <div class="absolute inset-0 bg-gradient-to-r from-[#4A154B] via-[#7A2E82] to-[#C41574]"></div>

                  <!-- Reversed gradient on hover -->
                  <div class="absolute inset-0 bg-gradient-to-r from-[#C41574] via-[#7A2E82] to-[#4A154B] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>

                  <!-- Shine sweep effect -->
                  <div class="absolute inset-0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-1000 bg-gradient-to-r from-transparent via-white/25 to-transparent"></div>

                  <!-- Subtle glow overlay -->
                  <div class="absolute inset-0 bg-[radial-gradient(circle_at_50%_50%,rgba(255,255,255,0.2),transparent_70%)] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>

                  <div class="relative flex items-center justify-center space-x-2 py-2.5 px-8 text-white font-bold">
                    <i data-lucide="pencil" class="w-4 h-4 group-hover/btn:rotate-12 transition-transform duration-300"></i>
                    <span>Edit</span>
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover/btn:translate-x-1 transition-transform duration-300"></i>
                  </div>
                </a>

              </div>

            </div>
          </div>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">

              <div class="flex items-start space-x-3">
                <img
                  src="../public/rcss-logo.png"
                  alt="RCSS Logo"
                  class="h-12 w-auto"
                />
                <div>
                  <p class="font-bold text-[#4A154B] text-sm">
                    Rajagiri College of Social Sciences
                  </p>
                  <p class="text-xs text-slate-600 mt-1">
                    Grievance Redressal Portal
                  </p>
                </div>
              </div>

              <div>
                <h4 class="font-bold text-sm text-[#4A154B] mb-2">Quick Links</h4>
                <ul class="space-y-1.5 text-xs text-slate-700">
                  <li>
                    <a href="dashboard.php" class="hover:text-[#E5097F] transition-colors inline-flex items-center space-x-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Dashboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="profile.php" class="hover:text-[#E5097F] transition-colors inline-flex items-center space-x-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>My Profile</span>
                    </a>
                  </li>
                  <li>
                    <a href="change_password.php" class="hover:text-[#E5097F] transition-colors inline-flex items-center space-x-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <div>
                <h4 class="font-bold text-sm text-[#4A154B] mb-2">Contact Support</h4>
                <ul class="space-y-1.5 text-xs text-slate-700">
                  <li class="flex items-center space-x-2">
                    <i data-lucide="mail" class="w-3.5 h-3.5 text-[#E5097F]"></i>
                    <span>admin.support@rajagiri.edu</span>
                  </li>
                  <li class="flex items-center space-x-2">
                    <i data-lucide="phone" class="w-3.5 h-3.5 text-[#E5097F]"></i>
                    <span>+91 484 XXX XXXX</span>
                  </li>
                  <li class="flex items-center space-x-2">
                    <i data-lucide="map-pin" class="w-3.5 h-3.5 text-[#E5097F]"></i>
                    <span>Kalamassery, Kochi, Kerala</span>
                  </li>
                </ul>
              </div>

            </div>

            <div class="border-t border-purple-300/50 pt-4">
              <div class="flex flex-col sm:flex-row items-center justify-between space-y-2 sm:space-y-0">
                <p class="text-xs text-slate-700 text-center sm:text-left">
                  &copy; <?= date('Y') ?>
                  <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
                  All rights reserved.
                </p>
                <p class="text-xs text-slate-700">
                  Powered by
                  <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">
                    Oréll Grievance
                  </span>
                </p>
              </div>
            </div>

          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- ====================== SCRIPTS ====================== -->
  <script>
    // Initialize Lucide icons
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Admin Profile Dropdown ----
    (function () {
      const btn       = document.getElementById('admin-dropdown-btn');
      const menu      = document.getElementById('admin-dropdown-menu');
      const chevron   = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');

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

    // ---- Logout Confirmation ----
    (function () {
      const logoutButtons = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];

      logoutButtons.forEach(function (btn) {
        if (!btn) return;

        btn.addEventListener('click', function (e) {
          const confirmed = window.confirm('Are you sure you want to log out?');
          if (!confirmed) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
          btn.classList.add('opacity-50', 'pointer-events-none');
        });
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>