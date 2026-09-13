<?php
/**
 * admin/dashboard.php
 * ---------------------------------------------------------------------------
 * Admin Dashboard — Rajagiri College Grievance Redressal Portal
 *
 * Auth Check   : case-insensitive role match against 'ADMIN'
 * DB Enum      : grievances.status IN ('Pending','In Progress','Disposed','Closed','Reopened')
 * Profile      : Fetches name + profile_picture from admin_profiles via LEFT JOIN
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
// 2. AUTH GUARD — case-insensitive role check
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
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
}

if ($conn && $conn->connect_errno) {
    $dbError = 'Database connection failed: ' . $conn->connect_error;
    $conn    = null;
}

// ---------------------------------------------------------------------------
// 4. FETCH ADMIN PROFILE (LEFT JOIN users + admin_profiles)
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => 'Admin',
    'name'            => '',
    'email'           => 'admin@rajagiri.edu',
    'profile_picture' => '',
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.id,
                        u.username,
                        u.role,
                        u.status,
                        ap.name            AS name,
                        ap.email           AS email,
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

                $adminData['username']        = $row['username']        ?? 'Admin';
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? 'admin@rajagiri.edu';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }

            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Admin Profile Fetch] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 5. DISPLAY NAME FALLBACK LOGIC
// ---------------------------------------------------------------------------
$displayName = !empty($adminData['name'])
    ? $adminData['name']
    : $adminData['username'];

$adminEmail = !empty($adminData['email'])
    ? $adminData['email']
    : 'admin@rajagiri.edu';

// ---------------------------------------------------------------------------
// 6. PROFILE PICTURE RESOLUTION
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');

    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// ---------------------------------------------------------------------------
// 7. INITIALS (fallback when no profile picture)
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
// 8. DYNAMIC METRIC QUERIES
// ---------------------------------------------------------------------------
$totalGrievances   = 0;
$pendingGrievances = 0;
$closedGrievances  = 0;

if ($conn !== null) {
    try {
        // ---- Total ----
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM grievances");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $totalGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        // ---- Pending ----
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM grievances
             WHERE status IN ('Pending', 'In Progress', 'Reopened')"
        );
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $pendingGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        // ---- Closed ----
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM grievances
             WHERE status IN ('Closed', 'Disposed')"
        );
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $closedGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

    } catch (Throwable $ex) {
        error_log('[Admin Dashboard Metrics] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. NAVIGATION CARDS CONFIG
// ---------------------------------------------------------------------------
$navCards = [
    ['title' => 'Settings',          'icon' => 'settings',       'href' => 'settings.php'],
    ['title' => 'Members',           'icon' => 'users',          'href' => 'members.php'],
    ['title' => 'Grievance',         'icon' => 'clipboard-list', 'href' => 'grievances.php'],
    ['title' => 'Grievance Reports', 'icon' => 'bar-chart-3',    'href' => 'reports.php'],
    ['title' => 'Mail Log',          'icon' => 'mail',           'href' => 'mail-log.php'],
];

// ---------------------------------------------------------------------------
// 10. HELPER
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
  <title>Admin Dashboard — Rajagiri College Grievance Portal</title>
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

      <!-- Toggle Icon (decorative) -->
      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <!-- Nav Icons -->
      <nav class="flex flex-col items-center space-y-6 flex-1">

        <!-- Dashboard / Home -->
        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-white shadow-lg ring-2 ring-white/30 transition-all hover:scale-110 hover:bg-white/30"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <!-- Profile -->
        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Profile
          </span>
        </a>

      </nav>

      <!-- Logout Button -->
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
            <!-- RCSS Logo -->
            <a href="dashboard.php" class="flex items-center group">
              <img
                src="../public/rcss-logo.png"
                alt="RCSS Logo"
                class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105"
              />
            </a>

            <!-- Divider -->
            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>

            <!-- Oréll Grievance Logo -->
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

              <!-- PROFILE AVATAR (dynamic) -->
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

              <!-- DISPLAY NAME -->
              <span class="hidden sm:block text-sm font-semibold text-slate-700">
                <?= e($displayName) ?>
              </span>

              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <!-- Dropdown Menu -->
            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden">

              <!-- Dropdown Header -->
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
                    <p class="text-xs text-slate-500 truncate"><?= e($adminEmail) ?></p>
                  </div>
                </div>
              </div>

              <!-- Option 1: My Profile -->
              <a href="profile.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover:scale-110 transition-transform"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <!-- Option 2: Change Password -->
              <a href="change_password.php"
                 class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <!-- Divider -->
              <div class="border-t border-slate-100 mt-2 pt-2">

                <!-- Option 3: Logout -->
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

        <?php if ($dbError): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- Institution Title -->
        <div class="text-center mb-10">
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-slate-800 mb-2">
            Rajagiri College of Social Sciences
          </h1>
          <h2 class="text-lg md:text-xl font-semibold text-slate-600">
            Admin Dashboard
          </h2>
        </div>

        <!-- ============ NAVIGATION CARDS (TOP ROW) ============ -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 md:gap-5 max-w-6xl mx-auto mb-10">
          <?php foreach ($navCards as $card): ?>
            <a href="<?= e($card['href']) ?>"
               class="group relative bg-gradient-to-br from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-2xl p-5 md:p-6 text-white shadow-lg hover:shadow-2xl transition-all duration-300 hover:scale-105 hover:-translate-y-1 overflow-hidden">

              <div class="absolute -top-6 -right-6 w-20 h-20 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
              <div class="absolute -bottom-6 -left-6 w-16 h-16 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-500 delay-75"></div>

              <div class="relative flex flex-col items-center justify-center text-center">
                <div class="w-12 h-12 md:w-14 md:h-14 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center mb-3 md:mb-4 group-hover:bg-white/30 group-hover:scale-110 transition-all duration-300">
                  <i data-lucide="<?= e($card['icon']) ?>" class="w-6 h-6 md:w-7 md:h-7"></i>
                </div>
                <p class="text-xs md:text-sm font-bold leading-tight">
                  <?= e($card['title']) ?>
                </p>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- ============ METRIC PILL CARDS (MIDDLE ROW) ============ -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 max-w-5xl mx-auto mb-12">

          <!-- Total -->
          <div class="flex items-center space-x-4 bg-white rounded-full shadow-lg border border-slate-100 p-3 pr-6 hover:shadow-xl hover:scale-[1.02] transition-all duration-300">
            <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-gradient-to-br from-pink-200 to-pink-100 flex items-center justify-center flex-shrink-0 shadow-inner">
              <i data-lucide="users-2" class="w-7 h-7 md:w-8 md:h-8 text-[#4A154B]"></i>
            </div>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-[#4A154B]">
                <?= (int) $totalGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-[#4A154B]">
                Total Grievance
              </span>
            </div>
          </div>

          <!-- Pending -->
          <div class="flex items-center space-x-4 bg-white rounded-full shadow-lg border border-slate-100 p-3 pr-6 hover:shadow-xl hover:scale-[1.02] transition-all duration-300">
            <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-gradient-to-br from-pink-200 to-pink-100 flex items-center justify-center flex-shrink-0 shadow-inner">
              <i data-lucide="users" class="w-7 h-7 md:w-8 md:h-8 text-[#4A154B]"></i>
            </div>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-[#4A154B]">
                <?= (int) $pendingGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-[#4A154B]">
                Pending Grievance
              </span>
            </div>
          </div>

          <!-- Closed -->
          <div class="flex items-center space-x-4 bg-white rounded-full shadow-lg border border-slate-100 p-3 pr-6 hover:shadow-xl hover:scale-[1.02] transition-all duration-300">
            <div class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-gradient-to-br from-pink-200 to-pink-100 flex items-center justify-center flex-shrink-0 shadow-inner">
              <i data-lucide="presentation" class="w-7 h-7 md:w-8 md:h-8 text-[#4A154B]"></i>
            </div>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-[#4A154B]">
                <?= (int) $closedGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-[#4A154B]">
                Closed Grievance
              </span>
            </div>
          </div>

        </div>

        <!-- ============ GRIEVANCE SUMMARY CALLOUT ============ -->
        <div class="flex justify-center">
          <a href="reports.php"
             class="group relative flex items-center space-x-5 bg-white rounded-3xl shadow-lg border border-slate-100 p-5 pr-8 hover:shadow-2xl hover:scale-[1.03] transition-all duration-300">

            <div class="relative w-20 h-20 md:w-24 md:h-24 rounded-2xl bg-gradient-to-br from-purple-100 to-pink-100 flex items-center justify-center flex-shrink-0 overflow-hidden">
              <i data-lucide="star" class="absolute top-2 right-2 w-4 h-4 text-[#C5A059]"></i>
              <i data-lucide="clipboard-edit" class="w-10 h-10 md:w-12 md:h-12 text-[#8B1E7E]"></i>
            </div>

            <div>
              <h3 class="text-base md:text-lg font-bold text-slate-800 mb-1">
                Grievance Summary
              </h3>
              <p class="text-sm md:text-base font-semibold text-[#E5097F] group-hover:text-[#8B1E7E] transition-colors">
                Click here to open
              </p>
            </div>

            <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-b-3xl transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></div>
          </a>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto">

            <!-- Footer Top: 3 Column Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">

              <!-- Col 1: Brand -->
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

              <!-- Col 2: Quick Links -->
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
                    <a href="change-password.php" class="hover:text-[#E5097F] transition-colors inline-flex items-center space-x-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <!-- Col 3: Contact -->
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

            <!-- Footer Divider -->
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

  <!-- Local JS -->
  <script src="../assets/js/index.js"></script>
</body>
</html>