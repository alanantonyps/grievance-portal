<?php
/**
 * staff/edit_profile.php
 * ---------------------------------------------------------------------------
 * Staff — Edit Profile (Personal + Login Details)
 * Rajagiri College Grievance Redressal Portal
 *
 * Database Schema (grievance_db):
 *   users   : id, username, password, role, status
 *   staff   : id, user_id, name, gender, email, contact_number,
 *             whatsapp_number, address, employee_id, department_id,
 *             designation_id, staff_type, profile_image
 *
 * Handles:
 *   • Pre-loading of staff profile (users LEFT JOIN staff)
 *   • POST processing for staff + users tables
 *   • Profile picture upload (JPG/JPEG/PNG/WEBP, max 2 MB)
 *   • Secure password update with password_hash()
 *   • Redirect to staff/profile.php on success
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
// 2. AUTH GUARD (TEACHER or NON_TEACHING)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['TEACHER', 'NON_TEACHING'], true)) {
    header('Location: ../login.php?role=staff');
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

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 4. HELPER — HTML ESCAPE
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FORM STATE (pre-filled defaults)
// ---------------------------------------------------------------------------
$formData = [
    'name'            => '',
    'address'         => '',
    'email'           => '',
    'contact_number'  => '',
    'whatsapp_number' => '',
    'username'        => '',
    'profile_image'   => '',
];

// ---------------------------------------------------------------------------
// 6. HANDLE POST SUBMISSION
// ---------------------------------------------------------------------------
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----- Collect & Sanitize -----
    $name            = trim((string) ($_POST['name']            ?? ''));
    $address         = trim((string) ($_POST['address']         ?? ''));
    $email           = trim((string) ($_POST['email']           ?? ''));
    $contactNumber   = trim((string) ($_POST['contact_number']  ?? ''));
    $whatsappNumber  = trim((string) ($_POST['whatsapp_number'] ?? ''));
    $username        = trim((string) ($_POST['username']        ?? ''));
    $password        = (string) ($_POST['password']             ?? '');
    $confirmPassword = (string) ($_POST['confirm_password']     ?? '');

    // ----- Validation -----
    if ($name === '') {
        $formErrors[] = 'Name is required.';
    }

    if ($email === '') {
        $formErrors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Please enter a valid email address.';
    }

    if ($contactNumber === '') {
        $formErrors[] = 'Contact number is required.';
    } elseif (!preg_match('/^[0-9]{6,15}$/', preg_replace('/\D/', '', $contactNumber))) {
        $formErrors[] = 'Please enter a valid contact number (6–15 digits).';
    }

    if ($whatsappNumber !== '' && !preg_match('/^[0-9]{6,15}$/', preg_replace('/\D/', '', $whatsappNumber))) {
        $formErrors[] = 'Please enter a valid WhatsApp number (6–15 digits).';
    }

    if ($username === '') {
        $formErrors[] = 'Username is required.';
    }

    // Password validation (only if either field has content)
    $updatePassword = false;
    if ($password !== '' || $confirmPassword !== '') {
        if ($password === '') {
            $formErrors[] = 'Please enter a password.';
        } elseif (strlen($password) < 6) {
            $formErrors[] = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirmPassword) {
            $formErrors[] = 'Passwords do not match.';
        } else {
            $updatePassword = true;
        }
    }

    // ----- Duplicate username check (excluding self) -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $chk->bind_param('si', $username, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $formErrors[] = 'This username is already taken. Please choose another.';
            }
            $chk->close();
        } catch (Throwable $ex) {
            error_log('[Staff Edit Profile Username Check] ' . $ex->getMessage());
        }
    }

    // ----- Duplicate email check (excluding self) -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare("SELECT id FROM staff WHERE email = ? AND user_id != ? LIMIT 1");
            $chk->bind_param('si', $email, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $formErrors[] = 'This email is already registered to another staff member.';
            }
            $chk->close();
        } catch (Throwable $ex) {
            error_log('[Staff Edit Profile Email Check] ' . $ex->getMessage());
        }
    }

    // ----- Profile Picture Upload -----
    $newProfilePictureRelative = null;

    if (!empty($_FILES['profile_picture']['name']) && (int) $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {

        $fileTmp  = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileSize = (int) $_FILES['profile_picture']['size'];
        $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $maxSize     = 2 * 1024 * 1024; // 2 MB

        if (!in_array($fileExt, $allowedExts, true)) {
            $formErrors[] = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
        } elseif ($fileSize > $maxSize) {
            $formErrors[] = 'Image size must be under 2 MB.';
        } else {
            $uploadDirAbs = __DIR__ . '/../uploads/profiles/';

            if (!is_dir($uploadDirAbs)) {
                @mkdir($uploadDirAbs, 0755, true);
            }

            if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
                $formErrors[] = 'Upload directory is not writable. Please contact support.';
            } else {
                $newFileName  = 'staff_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                $targetAbs    = $uploadDirAbs . $newFileName;
                $relativePath = 'uploads/profiles/' . $newFileName;

                if (move_uploaded_file($fileTmp, $targetAbs)) {
                    $newProfilePictureRelative = $relativePath;
                } else {
                    $formErrors[] = 'Failed to upload the profile picture. Please try again.';
                }
            }
        }
    }

    // ----- Save to DB if all valid -----
    if (empty($formErrors) && $conn !== null) {

        try {
            // -------- Start transaction --------
            $conn->begin_transaction();

            // -------- 1) Fetch old profile image for cleanup --------
            $oldProfileImage = '';
            $stmtOld = $conn->prepare("SELECT profile_image FROM staff WHERE user_id = ? LIMIT 1");
            if ($stmtOld) {
                $stmtOld->bind_param('i', $userId);
                $stmtOld->execute();
                $resOld = $stmtOld->get_result();
                if ($resOld && $resOld->num_rows > 0) {
                    $rowOld = $resOld->fetch_assoc();
                    $oldProfileImage = (string) ($rowOld['profile_image'] ?? '');
                }
                $stmtOld->close();
            }

            // -------- 2) Update staff table --------
            if ($newProfilePictureRelative !== null) {
                $sqlStaff = "UPDATE staff
                             SET name            = ?,
                                 address         = ?,
                                 email           = ?,
                                 contact_number  = ?,
                                 whatsapp_number = ?,
                                 profile_image   = ?
                             WHERE user_id = ?
                             LIMIT 1";

                $stmt = $conn->prepare($sqlStaff);
                if (!$stmt) {
                    throw new Exception('Prepare failed (staff update with image): ' . $conn->error);
                }

                $stmt->bind_param(
                    'ssssssi',
                    $name,
                    $address,
                    $email,
                    $contactNumber,
                    $whatsappNumber,
                    $newProfilePictureRelative,
                    $userId
                );
            } else {
                $sqlStaff = "UPDATE staff
                             SET name            = ?,
                                 address         = ?,
                                 email           = ?,
                                 contact_number  = ?,
                                 whatsapp_number = ?
                             WHERE user_id = ?
                             LIMIT 1";

                $stmt = $conn->prepare($sqlStaff);
                if (!$stmt) {
                    throw new Exception('Prepare failed (staff update): ' . $conn->error);
                }

                $stmt->bind_param(
                    'sssssi',
                    $name,
                    $address,
                    $email,
                    $contactNumber,
                    $whatsappNumber,
                    $userId
                );
            }

            if (!$stmt->execute()) {
                throw new Exception('Failed to update staff profile: ' . $stmt->error);
            }
            $stmt->close();

            // -------- 3) Delete old profile image if replaced --------
            if ($newProfilePictureRelative !== null && !empty($oldProfileImage) && $oldProfileImage !== $newProfilePictureRelative) {
                $oldAbs = __DIR__ . '/../' . ltrim($oldProfileImage, '/');
                if (file_exists($oldAbs) && is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }

            // -------- 4) Update users table (username + optional password) --------
            if ($updatePassword) {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception('Prepare failed (users update with password): ' . $conn->error);
                }
                $stmt->bind_param('ssi', $username, $hashedPassword, $userId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception('Prepare failed (users update): ' . $conn->error);
                }
                $stmt->bind_param('si', $username, $userId);
            }

            if (!$stmt->execute()) {
                throw new Exception('Failed to update login details: ' . $stmt->error);
            }
            $stmt->close();

            // -------- Commit --------
            $conn->commit();

            // -------- 5) Update session username --------
            $_SESSION['username'] = $username;

            // -------- 6) Flash success & redirect --------
            $_SESSION['flash_success'] = 'Profile updated successfully.';
            header('Location: profile.php');
            exit;

        } catch (Throwable $ex) {
            if ($conn instanceof mysqli) {
                $conn->rollback();
            }
            error_log('[Staff Edit Profile Save] ' . $ex->getMessage());
            $formErrors[] = $ex->getMessage() ?: 'A system error occurred while saving your profile.';
        }
    }

    // Repopulate $formData so the form doesn't reset on error
    $formData['name']            = $name;
    $formData['address']         = $address;
    $formData['email']           = $email;
    $formData['contact_number']  = $contactNumber;
    $formData['whatsapp_number'] = $whatsappNumber;
    $formData['username']        = $username;
}

// ---------------------------------------------------------------------------
// 7. PRE-LOAD PROFILE
// ---------------------------------------------------------------------------
$currentProfilePictureRelative = '';
$currentName                   = '';

if ($conn !== null) {
    try {
        $sql = "SELECT  u.username,
                        s.name            AS name,
                        s.address         AS address,
                        s.email           AS email,
                        s.contact_number  AS contact_number,
                        s.whatsapp_number AS whatsapp_number,
                        s.profile_image   AS profile_image
                FROM users u
                LEFT JOIN staff s ON s.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($formData['username'])) {
                $formData['username'] = $row['username'] ?? '';
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['name'] === '') {
                $formData['name'] = $row['name'] ?? '';
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['address'] === '') {
                $formData['address'] = $row['address'] ?? '';
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['email'] === '') {
                $formData['email'] = $row['email'] ?? '';
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['contact_number'] === '') {
                $formData['contact_number'] = $row['contact_number'] ?? '';
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['whatsapp_number'] === '') {
                $formData['whatsapp_number'] = $row['whatsapp_number'] ?? '';
            }

            $currentProfilePictureRelative = $row['profile_image'] ?? '';
            $currentName                   = $row['name']          ?? '';
        }

        $stmt->close();
    } catch (Throwable $ex) {
        error_log('[Staff Edit Profile Pre-load] ' . $ex->getMessage());
        if ($dbError === null) {
            $dbError = 'Unable to load your profile data.';
        }
    }
}

// ---------------------------------------------------------------------------
// Header display fallbacks (name > username)
// ---------------------------------------------------------------------------
$headerDisplayName  = !empty($formData['name']) ? $formData['name'] : $formData['username'];
$headerDisplayEmail = !empty($formData['email']) ? $formData['email'] : 'staff@rajagiri.edu';

// ---------------------------------------------------------------------------
// 8. PROFILE PICTURE RESOLUTION
// ---------------------------------------------------------------------------
$existingPreviewUrl = '';
if (!empty($currentProfilePictureRelative)) {
    if (file_exists(__DIR__ . '/../' . ltrim((string) $currentProfilePictureRelative, '/'))) {
        $existingPreviewUrl = '../' . ltrim((string) $currentProfilePictureRelative, '/');
    }
}

// ---------------------------------------------------------------------------
// 9. COUNTRY CODE LIST (display-only)
// ---------------------------------------------------------------------------
$countryCodes = [
    '+91'  => 'India (+91)',
    '+1'   => 'United States (+1)',
    '+44'  => 'United Kingdom (+44)',
    '+61'  => 'Australia (+61)',
    '+971' => 'United Arab Emirates (+971)',
    '+966' => 'Saudi Arabia (+966)',
    '+974' => 'Qatar (+974)',
    '+965' => 'Kuwait (+965)',
    '+968' => 'Oman (+968)',
    '+973' => 'Bahrain (+973)',
    '+60'  => 'Malaysia (+60)',
    '+65'  => 'Singapore (+65)',
    '+81'  => 'Japan (+81)',
    '+82'  => 'South Korea (+82)',
    '+86'  => 'China (+86)',
    '+49'  => 'Germany (+49)',
    '+33'  => 'France (+33)',
    '+39'  => 'Italy (+39)',
    '+34'  => 'Spain (+34)',
    '+31'  => 'Netherlands (+31)',
    '+41'  => 'Switzerland (+41)',
    '+46'  => 'Sweden (+46)',
    '+47'  => 'Norway (+47)',
    '+45'  => 'Denmark (+45)',
    '+64'  => 'New Zealand (+64)',
    '+27'  => 'South Africa (+27)',
    '+20'  => 'Egypt (+20)',
    '+234' => 'Nigeria (+234)',
    '+254' => 'Kenya (+254)',
    '+880' => 'Bangladesh (+880)',
    '+92'  => 'Pakistan (+92)',
    '+94'  => 'Sri Lanka (+94)',
    '+977' => 'Nepal (+977)',
    '+7'   => 'Russia (+7)',
    '+30'  => 'Greece (+30)',
    '+32'  => 'Belgium (+32)',
    '+36'  => 'Hungary (+36)',
    '+40'  => 'Romania (+40)',
    '+43'  => 'Austria (+43)',
    '+48'  => 'Poland (+48)',
    '+51'  => 'Peru (+51)',
    '+52'  => 'Mexico (+52)',
    '+54'  => 'Argentina (+54)',
    '+55'  => 'Brazil (+55)',
    '+56'  => 'Chile (+56)',
    '+57'  => 'Colombia (+57)',
    '+58'  => 'Venezuela (+58)',
    '+62'  => 'Indonesia (+62)',
    '+63'  => 'Philippines (+63)',
    '+66'  => 'Thailand (+66)',
    '+84'  => 'Vietnam (+84)',
    '+90'  => 'Turkey (+90)',
    '+98'  => 'Iran (+98)',
    '+212' => 'Morocco (+212)',
    '+213' => 'Algeria (+213)',
    '+216' => 'Tunisia (+216)',
    '+218' => 'Libya (+218)',
    '+220' => 'Gambia (+220)',
    '+221' => 'Senegal (+221)',
    '+233' => 'Ghana (+233)',
    '+237' => 'Cameroon (+237)',
    '+250' => 'Rwanda (+250)',
    '+251' => 'Ethiopia (+251)',
    '+255' => 'Tanzania (+255)',
    '+256' => 'Uganda (+256)',
    '+263' => 'Zimbabwe (+263)',
    '+351' => 'Portugal (+351)',
    '+352' => 'Luxembourg (+352)',
    '+353' => 'Ireland (+353)',
    '+354' => 'Iceland (+354)',
    '+355' => 'Albania (+355)',
    '+356' => 'Malta (+356)',
    '+357' => 'Cyprus (+357)',
    '+358' => 'Finland (+358)',
    '+359' => 'Bulgaria (+359)',
    '+370' => 'Lithuania (+370)',
    '+371' => 'Latvia (+371)',
    '+372' => 'Estonia (+372)',
    '+373' => 'Moldova (+373)',
    '+374' => 'Armenia (+374)',
    '+375' => 'Belarus (+375)',
    '+380' => 'Ukraine (+380)',
    '+381' => 'Serbia (+381)',
    '+385' => 'Croatia (+385)',
    '+386' => 'Slovenia (+386)',
    '+420' => 'Czech Republic (+420)',
    '+421' => 'Slovakia (+421)',
    '+852' => 'Hong Kong (+852)',
    '+853' => 'Macau (+853)',
    '+855' => 'Cambodia (+855)',
    '+856' => 'Laos (+856)',
    '+886' => 'Taiwan (+886)',
    '+960' => 'Maldives (+960)',
    '+961' => 'Lebanon (+961)',
    '+962' => 'Jordan (+962)',
    '+963' => 'Syria (+963)',
    '+964' => 'Iraq (+964)',
    '+967' => 'Yemen (+967)',
    '+970' => 'Palestine (+970)',
    '+972' => 'Israel (+972)',
    '+975' => 'Bhutan (+975)',
    '+976' => 'Mongolia (+976)',
    '+992' => 'Tajikistan (+992)',
    '+993' => 'Turkmenistan (+993)',
    '+994' => 'Azerbaijan (+994)',
    '+995' => 'Georgia (+995)',
    '+996' => 'Kyrgyzstan (+996)',
    '+998' => 'Uzbekistan (+998)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Profile — Staff | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

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
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40">

      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <nav class="flex flex-col items-center space-y-6 flex-1">

        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Dashboard
          </span>
        </a>

        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-white shadow-lg ring-2 ring-white/30 transition-all hover:scale-110 hover:bg-white/30"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
            Profile
          </span>
        </a>

      </nav>

      <a href="#"
         data-logout-trigger="1"
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
         MAIN CONTENT
         ============================================================ -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- ============ TOP HEADER ============ -->
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

          <div class="relative" id="staff-dropdown-container">
            <button id="staff-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-slate-100 transition-colors">

              <?php if ($existingPreviewUrl): ?>
                <img src="<?= e($existingPreviewUrl) ?>" alt="<?= e($headerDisplayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                            flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-slate-700">
                <?= e($headerDisplayName) ?>
              </span>
              <i data-lucide="chevron-down" id="staff-chevron"
                 class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="staff-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl
                        border border-slate-200 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($existingPreviewUrl): ?>
                    <img src="<?= e($existingPreviewUrl) ?>" alt="<?= e($headerDisplayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                                flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($headerDisplayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($headerDisplayEmail) ?></p>
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
            <a href="profile.php" class="hover:text-[#8B1E7E] transition-colors">User Details</a>
          </nav>
        </div>

        <!-- Error Banner -->
        <?php if ($dbError || !empty($formErrors)): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1">
              <?php if ($dbError): ?>
                <p><?= e($dbError) ?></p>
              <?php endif; ?>
              <?php foreach ($formErrors as $err): ?>
                <p><?= e($err) ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- ============ UPDATE FORM CARD ============ -->
        <div class="max-w-5xl mx-auto animate-fade-in-up">
          <div class="relative group/card">
            <div class="absolute -inset-0.5 bg-gradient-to-r from-[#4A154B] via-[#8B1E7E] to-[#E5097F] rounded-2xl blur opacity-10 group-hover/card:opacity-20 transition duration-500"></div>

            <div class="relative bg-white rounded-2xl shadow-xl border border-slate-200/60 overflow-hidden">

              <div class="bg-gradient-to-r from-slate-50 to-slate-100 px-6 py-5 border-b border-slate-200">
                <h2 class="text-xl md:text-2xl font-bold text-slate-800 flex items-center">
                  <i data-lucide="pencil-line" class="w-5 h-5 mr-2 text-[#8B1E7E]"></i>
                  Update
                </h2>
                <p class="text-sm text-slate-500 mt-1">Manage your personal information and login credentials</p>
              </div>

              <form action="edit_profile.php" method="POST" enctype="multipart/form-data" class="p-6 md:p-8 space-y-8">

                <!-- ================= PERSONAL DETAILS ================= -->
                <div class="space-y-6">

                  <!-- Row 1: Name | Address -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div class="space-y-2">
                      <label for="name" class="block text-sm font-semibold text-slate-700">
                        Name <span class="text-red-500">*</span>
                      </label>
                      <div class="relative">
                        <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input
                          type="text"
                          id="name"
                          name="name"
                          value="<?= e($formData['name']) ?>"
                          required
                          placeholder="Enter your full name"
                          class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        />
                      </div>
                    </div>

                    <div class="space-y-2">
                      <label for="address" class="block text-sm font-semibold text-slate-700">
                        Address
                      </label>
                      <div class="relative">
                        <i data-lucide="map-pin" class="absolute left-3 top-3 w-5 h-5 text-slate-400"></i>
                        <textarea
                          id="address"
                          name="address"
                          rows="3"
                          placeholder="Enter your residential address"
                          class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl resize-none
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        ><?= e($formData['address']) ?></textarea>
                      </div>
                    </div>

                  </div>

                  <!-- Row 2: Email | Image -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div class="space-y-2">
                      <label for="email" class="block text-sm font-semibold text-slate-700">
                        Email <span class="text-red-500">*</span>
                      </label>
                      <div class="relative">
                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input
                          type="email"
                          id="email"
                          name="email"
                          value="<?= e($formData['email']) ?>"
                          required
                          placeholder="Enter your email address"
                          class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        />
                      </div>
                    </div>

                    <div class="space-y-2">
                      <label for="profile_picture" class="block text-sm font-semibold text-slate-700">
                        Image
                      </label>

                      <div class="flex items-center space-x-3">
                        <input
                          type="file"
                          id="profile_picture"
                          name="profile_picture"
                          accept=".jpg,.jpeg,.png,.webp"
                          class="hidden"
                          onchange="previewProfileImage(event)"
                        />

                        <button
                          type="button"
                          onclick="document.getElementById('profile_picture').click()"
                          class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 border-2 border-slate-200
                                 rounded-xl text-sm font-semibold text-slate-700 transition-colors
                                 focus:outline-none focus:border-[#4A154B]"
                        >
                          Choose file
                        </button>

                        <span id="file-chosen-text" class="text-sm text-slate-500 truncate">
                          <?= $existingPreviewUrl ? 'Current image loaded' : 'No file chosen' ?>
                        </span>

                        <?php if ($existingPreviewUrl): ?>
                          <img id="profile-preview"
                               src="<?= e($existingPreviewUrl) ?>"
                               alt="Preview"
                               class="w-10 h-10 rounded-lg object-cover border-2 border-slate-200" />
                        <?php else: ?>
                          <img id="profile-preview"
                               src=""
                               alt="Preview"
                               class="hidden w-10 h-10 rounded-lg object-cover border-2 border-slate-200" />
                        <?php endif; ?>
                      </div>
                      <p class="text-xs text-slate-500 mt-1">Allowed: JPG, JPEG, PNG, WEBP (max 2 MB)</p>
                    </div>

                  </div>

                  <!-- Row 3: Mobile | WhatsApp -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Mobile -->
                    <div class="space-y-2">
                      <label for="contact_number" class="block text-sm font-semibold text-slate-700">
                        Mobile Number <span class="text-red-500">*</span>
                      </label>
                      <div class="flex gap-2">

                        <div class="relative w-40 flex-shrink-0" data-country-select="mobile">
                          <button type="button"
                                  class="country-select-trigger w-full flex items-center justify-between px-3 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 text-sm font-medium hover:border-[#4A154B]/40 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 transition-all">
                            <span class="country-select-label truncate">+91</span>
                            <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 pointer-events-none flex-shrink-0"></i>
                          </button>

                          <div class="country-select-panel hidden absolute z-50 mt-1 w-72 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden">
                            <div class="p-3 border-b border-slate-200 bg-slate-50">
                              <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                <input
                                  type="text"
                                  class="country-select-search w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg
                                         focus:outline-none focus:border-[#4A154B] focus:ring-2 focus:ring-[#4A154B]/10
                                         transition-all text-sm"
                                  placeholder="Search country..."
                                />
                              </div>
                            </div>

                            <div class="country-select-list max-h-56 overflow-y-auto">
                              <?php foreach ($countryCodes as $code => $label): ?>
                                <button
                                  type="button"
                                  class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm transition-colors
                                         <?= $code === '+91' ? 'bg-purple-50 text-[#8B1E7E] font-semibold' : 'text-slate-700 hover:bg-purple-50' ?>"
                                  data-value="<?= e($code) ?>"
                                  data-label="<?= e($label) ?>"
                                  data-search="<?= e(strtolower($code . ' ' . $label)) ?>"
                                >
                                  <span class="font-medium flex-shrink-0"><?= e($code) ?></span>
                                  <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate"><?= e($label) ?></span>
                                  <?php if ($code === '+91'): ?>
                                    <i data-lucide="check-circle" class="w-4 h-4 text-[#8B1E7E] flex-shrink-0"></i>
                                  <?php endif; ?>
                                </button>
                              <?php endforeach; ?>
                            </div>

                            <div class="country-select-empty hidden px-4 py-3 text-sm text-slate-500 text-center">
                              No countries found
                            </div>
                          </div>
                        </div>

                        <div class="relative flex-1">
                          <i data-lucide="smartphone" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                          <input
                            type="tel"
                            id="contact_number"
                            name="contact_number"
                            value="<?= e($formData['contact_number']) ?>"
                            required
                            placeholder="Enter mobile number"
                            class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl
                                   focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                   transition-all text-slate-800 font-medium placeholder-slate-400
                                   hover:border-[#4A154B]/40"
                          />
                        </div>

                      </div>
                      <p class="text-xs text-slate-500 mt-1">Country code is displayed for reference only.</p>
                    </div>

                    <!-- WhatsApp -->
                    <div class="space-y-2">
                      <label for="whatsapp_number" class="block text-sm font-semibold text-slate-700">
                        WhatsApp Number
                      </label>
                      <div class="flex gap-2">

                        <div class="relative w-40 flex-shrink-0" data-country-select="whatsapp">
                          <button type="button"
                                  class="country-select-trigger w-full flex items-center justify-between px-3 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 text-sm font-medium hover:border-[#4A154B]/40 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10 transition-all">
                            <span class="country-select-label truncate">--SELECT--</span>
                            <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 pointer-events-none flex-shrink-0"></i>
                          </button>

                          <div class="country-select-panel hidden absolute z-50 mt-1 w-72 bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden">
                            <div class="p-3 border-b border-slate-200 bg-slate-50">
                              <div class="relative">
                                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                <input
                                  type="text"
                                  class="country-select-search w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg
                                         focus:outline-none focus:border-[#4A154B] focus:ring-2 focus:ring-[#4A154B]/10
                                         transition-all text-sm"
                                  placeholder="Search country..."
                                />
                              </div>
                            </div>

                            <div class="country-select-list max-h-56 overflow-y-auto">
                              <button
                                type="button"
                                class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm transition-colors bg-purple-50 text-[#8B1E7E] font-semibold"
                                data-value=""
                                data-label="--SELECT--"
                                data-search="select"
                              >
                                <span class="font-medium">--SELECT--</span>
                                <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate">Choose a country code</span>
                              </button>

                              <?php foreach ($countryCodes as $code => $label): ?>
                                <button
                                  type="button"
                                  class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm text-slate-700 hover:bg-purple-50 transition-colors"
                                  data-value="<?= e($code) ?>"
                                  data-label="<?= e($label) ?>"
                                  data-search="<?= e(strtolower($code . ' ' . $label)) ?>"
                                >
                                  <span class="font-medium flex-shrink-0"><?= e($code) ?></span>
                                  <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate"><?= e($label) ?></span>
                                </button>
                              <?php endforeach; ?>
                            </div>

                            <div class="country-select-empty hidden px-4 py-3 text-sm text-slate-500 text-center">
                              No countries found
                            </div>
                          </div>
                        </div>

                        <div class="relative flex-1">
                          <i data-lucide="message-circle" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                          <input
                            type="tel"
                            id="whatsapp_number"
                            name="whatsapp_number"
                            value="<?= e($formData['whatsapp_number']) ?>"
                            placeholder="Enter Your Whatsapp No"
                            class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl
                                   focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                   transition-all text-slate-800 font-medium placeholder-slate-400
                                   hover:border-[#4A154B]/40"
                          />
                        </div>

                      </div>
                      <p class="text-xs text-slate-500 mt-1">Optional — leave blank if not applicable.</p>
                    </div>

                  </div>

                </div>

                <!-- ================= LOGIN DETAILS ================= -->
                <div class="space-y-6">

                  <div class="bg-[#4A154B] rounded-xl px-4 py-3 relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>
                    <h3 class="relative text-white font-bold text-sm flex items-center">
                      <i data-lucide="lock" class="w-4 h-4 mr-2"></i>
                      Login Details
                    </h3>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                    <div class="space-y-2">
                      <label for="username" class="block text-sm font-semibold text-slate-700">
                        Username <span class="text-red-500">*</span>
                      </label>
                      <div class="relative">
                        <i data-lucide="user-circle" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input
                          type="text"
                          id="username"
                          name="username"
                          value="<?= e($formData['username']) ?>"
                          required
                          placeholder="Enter username"
                          class="w-full pl-11 pr-4 py-3 border-2 border-slate-200 rounded-xl
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        />
                      </div>
                    </div>

                    <div class="space-y-2">
                      <label for="password" class="block text-sm font-semibold text-slate-700">
                        Password
                      </label>
                      <div class="relative">
                        <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input
                          type="password"
                          id="password"
                          name="password"
                          placeholder="••••••••"
                          autocomplete="new-password"
                          class="w-full pl-11 pr-12 py-3 border-2 border-slate-200 rounded-xl
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        />
                        <button
                          type="button"
                          onclick="togglePasswordVisibility('password', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#4A154B] transition-colors"
                          aria-label="Toggle password visibility"
                        >
                          <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                      </div>
                      <p class="text-xs text-slate-500">Leave blank to keep current password</p>
                    </div>

                    <div class="space-y-2">
                      <label for="confirm_password" class="block text-sm font-semibold text-slate-700">
                        Confirm Password
                      </label>
                      <div class="relative">
                        <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input
                          type="password"
                          id="confirm_password"
                          name="confirm_password"
                          placeholder="••••••••"
                          autocomplete="new-password"
                          class="w-full pl-11 pr-12 py-3 border-2 border-slate-200 rounded-xl
                                 focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                                 transition-all text-slate-800 font-medium placeholder-slate-400
                                 hover:border-[#4A154B]/40"
                        />
                        <button
                          type="button"
                          onclick="togglePasswordVisibility('confirm_password', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#4A154B] transition-colors"
                          aria-label="Toggle password visibility"
                        >
                          <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                      </div>
                      <p class="text-xs text-slate-500">Must match the password above</p>
                    </div>

                  </div>

                </div>

                <!-- ================= ACTIONS ================= -->
                <div class="flex flex-col sm:flex-row justify-end items-center gap-3 pt-6 border-t border-slate-200">

                  <a href="profile.php"
                     class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl shadow-md shadow-pink-500/20 hover:shadow-pink-500/40 transition-all duration-300 hover:scale-[1.02] active:scale-95">
                    <div class="absolute inset-0 bg-gradient-to-r from-[#E5097F] via-[#C41574] to-[#E5097F]"></div>
                    <div class="absolute inset-0 bg-gradient-to-r from-[#C41574] via-[#E5097F] to-[#C41574] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>
                    <div class="relative flex items-center justify-center space-x-2 py-2.5 px-8 text-white font-bold">
                      <i data-lucide="x" class="w-4 h-4 group-hover/btn:rotate-90 transition-transform duration-300"></i>
                      <span>Close</span>
                    </div>
                  </a>

                  <button type="submit"
                          class="group/btn relative w-full sm:w-auto overflow-hidden rounded-xl shadow-md shadow-emerald-500/20 hover:shadow-emerald-500/40 transition-all duration-300 hover:scale-[1.02] active:scale-95">
                    <div class="absolute inset-0 bg-gradient-to-r from-[#006837] via-[#008a4a] to-[#006837]"></div>
                    <div class="absolute inset-0 bg-gradient-to-r from-[#008a4a] via-[#006837] to-[#008a4a] opacity-0 group-hover/btn:opacity-100 transition-opacity duration-500"></div>
                    <div class="absolute inset-0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-1000 bg-gradient-to-r from-transparent via-white/25 to-transparent"></div>
                    <div class="relative flex items-center justify-center space-x-2 py-2.5 px-8 text-white font-bold">
                      <i data-lucide="save" class="w-4 h-4 group-hover/btn:scale-110 transition-transform duration-300"></i>
                      <span>Update</span>
                    </div>
                  </button>

                </div>

              </form>

            </div>
          </div>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto">
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

  <!-- ============================================================ -->
  <!-- LOGOUT CONFIRMATION MODAL                                     -->
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
          <span class="font-bold text-[#8B1E7E] break-words"><?= e($headerDisplayName) ?></span>.
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

  <!-- ====================== SCRIPTS ====================== -->
  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Image Preview ----
    function previewProfileImage(event) {
      const file = event.target.files && event.target.files[0];
      const previewEl = document.getElementById('profile-preview');
      const textEl    = document.getElementById('file-chosen-text');

      if (!file) return;

      const reader = new FileReader();
      reader.onload = function (e) {
        previewEl.src = e.target.result;
        previewEl.classList.remove('hidden');
      };
      reader.readAsDataURL(file);

      if (textEl) {
        textEl.textContent = file.name;
      }
    }

    // ---- Toggle Password Visibility ----
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon && typeof lucide !== 'undefined') {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        lucide.createIcons({ targets: [icon] });
      }
    }

    // ---- Searchable Country Code Selects (display-only) ----
    (function initCountrySelects() {
      const wrappers = document.querySelectorAll('[data-country-select]');

      wrappers.forEach(function (wrapper) {
        const trigger  = wrapper.querySelector('.country-select-trigger');
        const label    = wrapper.querySelector('.country-select-label');
        const panel    = wrapper.querySelector('.country-select-panel');
        const search   = wrapper.querySelector('.country-select-search');
        const options  = wrapper.querySelectorAll('.country-select-option');
        const emptyMsg = wrapper.querySelector('.country-select-empty');

        if (!trigger || !panel) return;

        trigger.addEventListener('click', function (e) {
          e.stopPropagation();

          document.querySelectorAll('.country-select-panel').forEach(function (p) {
            if (p !== panel) p.classList.add('hidden');
          });

          const isOpen = !panel.classList.contains('hidden');
          panel.classList.toggle('hidden', isOpen);

          if (!isOpen) {
            setTimeout(function () { search && search.focus(); }, 30);
          } else if (search) {
            search.value = '';
            filterOptions('');
          }
        });

        function filterOptions(term) {
          const t = term.trim().toLowerCase();
          let visibleCount = 0;

          options.forEach(function (opt) {
            const haystack = opt.getAttribute('data-search') || '';
            const match = t === '' || haystack.indexOf(t) !== -1;
            opt.style.display = match ? '' : 'none';
            if (match) visibleCount++;
          });

          if (emptyMsg) {
            emptyMsg.classList.toggle('hidden', visibleCount !== 0);
          }
        }

        if (search) {
          search.addEventListener('input', function () {
            filterOptions(search.value);
          });
          search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
              panel.classList.add('hidden');
            }
          });
        }

        options.forEach(function (opt) {
          opt.addEventListener('click', function (e) {
            e.stopPropagation();

            const value = opt.getAttribute('data-value') || '';
            const lbl   = opt.getAttribute('data-label') || value;

            if (label) label.textContent = lbl || '--SELECT--';

            options.forEach(function (o) {
              o.classList.remove('bg-purple-50', 'text-[#8B1E7E]', 'font-semibold');
            });
            opt.classList.add('bg-purple-50', 'text-[#8B1E7E]', 'font-semibold');

            panel.classList.add('hidden');
            if (search) search.value = '';
            filterOptions('');
          });
        });

        document.addEventListener('click', function (e) {
          if (!wrapper.contains(e.target)) {
            panel.classList.add('hidden');
          }
        });
      });
    })();

    // ============================================================
    // STAFF PROFILE DROPDOWN
    // ============================================================
    (function () {
      const btn       = document.getElementById('staff-dropdown-btn');
      const menu      = document.getElementById('staff-dropdown-menu');
      const chevron   = document.getElementById('staff-chevron');
      const container = document.getElementById('staff-dropdown-container');

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

    const LOGOUT_URL = '../logout.php?role=staff';

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