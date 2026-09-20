<?php
/**
 * parent/dashboard.php
 * ---------------------------------------------------------------------------
 * Parent — Grievance Details Dashboard
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Auth guard (PARENT only)
 *   • Top navbar with RCSS logo + Oréll Grievance branding + parent profile dropdown
 *   • Collapsible sidebar (Home, Profile, Change Password, Logout)
 *   • Grievance list table (own grievances only)
 *   • Live search + entries-per-page selector
 *   • Status badges with color coding
 *   • Create Grievance modal (with file upload → stored in uploads/grievances/)
 *   • Edit Grievance modal (only for Pending / Reopened)
 *   • Dispose Grievance action (X icon — only for Pending / In Progress / Reopened)
 *   • View details modal with in-page image/PDF preview overlay
 *   • Reminder / Reopen action hooks
 *   • Empty state & pagination counter
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
// 2. AUTH GUARD (Parent only)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'PARENT') {
    header('Location: ../login.php?role=parent');
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
// 4. HELPER — HTML ESCAPE
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. HELPER — STATUS BADGE
// ---------------------------------------------------------------------------
function statusBadge(string $status): string
{
    $status = trim($status);

    $map = [
        'Pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'In Progress' => 'bg-blue-100 text-blue-800 border-blue-200',
        'Disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'Closed'      => 'bg-slate-200 text-slate-700 border-slate-300',
        'Reopened'    => 'bg-rose-100 text-rose-800 border-rose-200',
    ];

    $classes = $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';

    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border '
        . $classes . '">' . e($status) . '</span>';
}

// ---------------------------------------------------------------------------
// 6. CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// 7. HANDLE POST ACTIONS (create_grievance | update_grievance | dispose_grievance)
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    // ---- CSRF check ----
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $flashError = 'Invalid session token. Please refresh and try again.';
        $_SESSION['flash_error'] = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    // =======================================================================
    // ACTION: CREATE GRIEVANCE
    // =======================================================================
    if ($action === 'create_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            $targetPath     = null;
            $attachmentPath = null;

            try {
                $grievanceTypeId = (int) ($_POST['grievance_type_id'] ?? 0);
                $subject         = trim((string) ($_POST['subject']       ?? ''));
                $description     = trim((string) ($_POST['description']   ?? ''));

                if ($grievanceTypeId <= 0) {
                    throw new Exception('Please select a valid Grievance Type.');
                }
                if ($subject === '') {
                    throw new Exception('Subject is required.');
                }
                if (mb_strlen($subject, 'UTF-8') > 120) {
                    throw new Exception('Subject cannot exceed 120 characters.');
                }
                if (mb_strlen($description, 'UTF-8') > 420) {
                    throw new Exception('Description cannot exceed 420 characters.');
                }

                $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
                $chkType->bind_param('i', $grievanceTypeId);
                $chkType->execute();
                if ($chkType->get_result()->num_rows === 0) {
                    $chkType->close();
                    throw new Exception('Selected Grievance Type is invalid or inactive.');
                }
                $chkType->close();

                // ---- Auto-generate unique grievance_number ----
                $yearPrefix = 'GRV-' . date('Y') . '-';
                $grievanceNumber = '';

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = $yearPrefix . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                    $chkNum = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
                    $chkNum->bind_param('s', $candidate);
                    $chkNum->execute();
                    $exists = $chkNum->get_result()->num_rows > 0;
                    $chkNum->close();

                    if (!$exists) {
                        $grievanceNumber = $candidate;
                        break;
                    }
                }

                if ($grievanceNumber === '') {
                    throw new Exception('Unable to generate a unique grievance number. Please try again.');
                }

                // ---- Handle file attachment ----
                if (!empty($_FILES['attachment']['name'])) {
                    $file = $_FILES['attachment'];

                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $maxBytes = 5 * 1024 * 1024;

                        if ((int) $file['size'] > $maxBytes) {
                            throw new Exception('Attachment exceeds the maximum allowed size of 5 MB.');
                        }

                        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                        $ext        = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

                        if (!in_array($ext, $allowedExt, true)) {
                            throw new Exception('Invalid file type. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX.');
                        }

                        $uploadDir = __DIR__ . '/../uploads/grievances/';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            throw new Exception('Upload directory is not writable. Please contact support.');
                        }

                        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo((string) $file['name'], PATHINFO_FILENAME));
                        if ($safeBase === '' || $safeBase === null) {
                            $safeBase = 'file';
                        }
                        $safeBase = substr($safeBase, 0, 60);

                        $newFileName = 'grv_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
                        $targetPath  = $uploadDir . $newFileName;

                        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                            throw new Exception('Failed to save the uploaded attachment. Please try again.');
                        }

                        $attachmentPath = 'uploads/grievances/' . $newFileName;
                    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                        throw new Exception('File upload error (code ' . (int) $file['error'] . '). Please try again.');
                    }
                }

                // ---- Insert grievance ----
                $initialStatus = 'Pending';

                $sql = "INSERT INTO grievances
                            (grievance_number, grievance_type_id, complainant_user_id,
                             subject, description, attachment_path, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param(
                    'siissss',
                    $grievanceNumber,
                    $grievanceTypeId,
                    $userId,
                    $subject,
                    $description,
                    $attachmentPath,
                    $initialStatus
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to submit grievance: ' . $stmt->error);
                }

                $stmt->close();

                $flashSuccess = 'Grievance submitted successfully. Your reference number is ' . $grievanceNumber . '.';

            } catch (Throwable $ex) {
                if (!empty($targetPath) && file_exists($targetPath)) {
                    @unlink($targetPath);
                }
                error_log('[Parent Create Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while submitting your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    // =======================================================================
    // ACTION: UPDATE (EDIT) GRIEVANCE
    // =======================================================================
    if ($action === 'update_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $grievanceId     = (int) ($_POST['grievance_id']       ?? 0);
                $grievanceTypeId = (int) ($_POST['grievance_type_id']  ?? 0);
                $subject         = trim((string) ($_POST['subject']       ?? ''));
                $description     = trim((string) ($_POST['description']   ?? ''));

                if ($grievanceId <= 0) {
                    throw new Exception('Invalid grievance reference.');
                }
                if ($grievanceTypeId <= 0) {
                    throw new Exception('Please select a valid Grievance Type.');
                }
                if ($subject === '') {
                    throw new Exception('Subject is required.');
                }
                if (mb_strlen($subject, 'UTF-8') > 120) {
                    throw new Exception('Subject cannot exceed 120 characters.');
                }
                if (mb_strlen($description, 'UTF-8') > 420) {
                    throw new Exception('Description cannot exceed 420 characters.');
                }

                $chk = $conn->prepare(
                    "SELECT id, status
                     FROM grievances
                     WHERE id = ? AND complainant_user_id = ?
                     LIMIT 1"
                );
                $chk->bind_param('ii', $grievanceId, $userId);
                $chk->execute();
                $chkRes = $chk->get_result();

                if ($chkRes->num_rows === 0) {
                    $chk->close();
                    throw new Exception('Grievance not found or does not belong to your account.');
                }

                $chkRow    = $chkRes->fetch_assoc();
                $curStatus = (string) ($chkRow['status'] ?? '');
                $chk->close();

                if (!in_array($curStatus, ['Pending', 'Reopened'], true)) {
                    throw new Exception('This grievance can no longer be edited (current status: ' . $curStatus . ').');
                }

                $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
                $chkType->bind_param('i', $grievanceTypeId);
                $chkType->execute();
                if ($chkType->get_result()->num_rows === 0) {
                    $chkType->close();
                    throw new Exception('Selected Grievance Type is invalid or inactive.');
                }
                $chkType->close();

                $sql = "UPDATE grievances
                        SET grievance_type_id = ?,
                            subject           = ?,
                            description       = ?,
                            updated_at        = NOW()
                        WHERE id = ? AND complainant_user_id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param(
                    'issii',
                    $grievanceTypeId,
                    $subject,
                    $description,
                    $grievanceId,
                    $userId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to update grievance: ' . $stmt->error);
                }

                $stmt->close();

                $flashSuccess = 'Grievance updated successfully.';

            } catch (Throwable $ex) {
                error_log('[Parent Update Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    // =======================================================================
    // ACTION: DISPOSE GRIEVANCE
    // =======================================================================
    if ($action === 'dispose_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $grievanceId = (int) ($_POST['grievance_id'] ?? 0);

                if ($grievanceId <= 0) {
                    throw new Exception('Invalid grievance reference.');
                }

                $chk = $conn->prepare(
                    "SELECT id, status
                     FROM grievances
                     WHERE id = ? AND complainant_user_id = ?
                     LIMIT 1"
                );
                $chk->bind_param('ii', $grievanceId, $userId);
                $chk->execute();
                $chkRes = $chk->get_result();

                if ($chkRes->num_rows === 0) {
                    $chk->close();
                    throw new Exception('Grievance not found or does not belong to your account.');
                }

                $chkRow    = $chkRes->fetch_assoc();
                $curStatus = (string) ($chkRow['status'] ?? '');
                $chk->close();

                if (!in_array($curStatus, ['Pending', 'In Progress', 'Reopened'], true)) {
                    throw new Exception('This grievance cannot be disposed (current status: ' . $curStatus . ').');
                }

                $newStatus = 'Disposed';

                $sql = "UPDATE grievances
                        SET status     = ?,
                            updated_at = NOW()
                        WHERE id = ? AND complainant_user_id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param('sii', $newStatus, $grievanceId, $userId);

                if (!$stmt->execute()) {
                    throw new Exception('Failed to dispose grievance: ' . $stmt->error);
                }

                $stmt->close();

                $flashSuccess = 'Grievance has been marked as Disposed.';

            } catch (Throwable $ex) {
                error_log('[Parent Dispose Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while disposing your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
        exit;
    }
}

// ---- Pick up flash messages ----
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// 8. FETCH PARENT PROFILE
// ---------------------------------------------------------------------------
$parentData = [
    'username'      => $_SESSION['username'] ?? 'Parent',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        p.name,
                        p.email,
                        p.profile_image
                FROM users u
                LEFT JOIN parents p ON p.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $parentData['username']      = $row['username']      ?? $parentData['username'];
                $parentData['name']          = $row['name']          ?? '';
                $parentData['email']         = $row['email']         ?? '';
                $parentData['profile_image'] = $row['profile_image'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($parentData['name']) ? $parentData['name'] : $parentData['username'];
$displayEmail = !empty($parentData['email']) ? $parentData['email'] : 'parent@rajagiri.edu';

// ---------------------------------------------------------------------------
// PROFILE PICTURE RESOLUTION
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($parentData['profile_image'])) {
    $relative     = ltrim((string) $parentData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

// ---------------------------------------------------------------------------
// 9. FETCH ACTIVE GRIEVANCE TYPES
// ---------------------------------------------------------------------------
$grievanceTypes = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $grievanceTypes[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievance Types] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 10. FETCH PARENT'S GRIEVANCES (with type join + attachment_path)
// ---------------------------------------------------------------------------
$grievances = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  g.id,
                        g.grievance_number,
                        g.subject,
                        g.description,
                        g.attachment_path,
                        g.status,
                        g.reply_details,
                        g.created_at,
                        g.updated_at,
                        g.grievance_type_id,
                        gt.type_name
                FROM grievances g
                LEFT JOIN grievance_types gt ON gt.id = g.grievance_type_id
                WHERE g.complainant_user_id = ?
                ORDER BY g.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $grievances[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievances] ' . $ex->getMessage());
    }
}

$totalGrievances = count($grievances);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Details — Parent | Rajagiri College Grievance Portal</title>
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
            flashIn: {
              '0%':   { opacity: '0', transform: 'translateY(-10px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            flashOut: {
              '0%':   { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' },
              '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards'
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
    <aside id="parentSidebar"
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
           class="group relative w-full h-12 rounded-xl bg-white/20 backdrop-blur-sm
                  flex items-center text-white shadow-lg ring-2 ring-white/30
                  transition-all hover:bg-white/30
                  px-3">
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
                  flex items-center text-white transition-all
                  px-3">
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
                  flex items-center text-white transition-all
                  px-3">
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

    <!-- MAIN CONTENT WRAPPER -->
    <div id="parentMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="relative" id="parent-dropdown-container">
            <button id="parent-dropdown-btn"
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
              <i data-lucide="chevron-down" id="parent-chevron"
                 class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="parent-dropdown-menu"
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

        <?php if ($dbError): ?>
          <div class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start space-x-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">
                Grievance Details
              </h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
                  Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="dashboard.php" class="hover:text-[#8B1E7E] transition-colors">Grievance</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Grievance Details</span>
              </nav>
            </div>

            <button type="button"
                    onclick="openCreateGrievanceModal()"
                    title="Add Grievance"
                    class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                           text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                           transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
              <i data-lucide="plus" class="w-5 h-5"></i>
            </button>

          </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3
                      flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-[#006837] flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3
                      flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-7xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text"
                       id="searchInput"
                       placeholder="Search.."
                       autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm
                              focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                              hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>

            </div>
          </div>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-7xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <table class="w-full table-fixed" id="grievancesTable">
              <colgroup>
                <col style="width: 4%;">
                <col style="width: 13%;">
                <col style="width: 13%;">
                <col style="width: 9%;">
                <col style="width: 20%;">
                <col style="width: 9%;">
                <col style="width: 12%;">
                <col style="width: 20%;">
              </colgroup>
              <thead>
                <tr class="bg-[#4A154B] text-white">
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Sl.No.</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Number</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Type</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Date</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Subject</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Status</th>
                  <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Actions</th>
                  <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Remainder / Reopen</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100" id="grievancesTableBody">

                <?php if (empty($grievances)): ?>

                  <tr>
                    <td colspan="8" class="px-4 py-16 text-center text-slate-500">
                      <div class="flex flex-col items-center justify-center">
                        <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                          <i data-lucide="inbox" class="w-8 h-8 text-[#8B1E7E]"></i>
                        </div>
                        <p class="text-lg font-semibold text-slate-700">No data available in table</p>
                        <p class="text-sm text-slate-500 mt-1">
                          Click the "+" button above to submit your first grievance.
                        </p>
                      </div>
                    </td>
                  </tr>

                <?php else: ?>

                  <?php foreach ($grievances as $index => $row): ?>
                    <?php
                      $gId         = (int) $row['id'];
                      $gTypeId     = (int) ($row['grievance_type_id'] ?? 0);
                      $gNumber     = (string) ($row['grievance_number'] ?? '—');
                      $gType       = (string) ($row['type_name']        ?? '—');
                      $gSubject    = (string) ($row['subject']          ?? '—');
                      $gDesc       = (string) ($row['description']      ?? '');
                      $gReply      = (string) ($row['reply_details']    ?? '');
                      $gAttachment = (string) ($row['attachment_path']  ?? '');
                      $gStatus     = (string) ($row['status']           ?? 'Pending');
                      $gCreated    = !empty($row['created_at']) ? date('d M y', strtotime((string) $row['created_at'])) : '—';

                      $canEdit     = in_array($gStatus, ['Pending', 'Reopened'], true);
                      $canDispose  = in_array($gStatus, ['Pending', 'In Progress', 'Reopened'], true);
                      $canRemind   = in_array($gStatus, ['Pending', 'In Progress'], true);
                      $canReopen   = in_array($gStatus, ['Disposed', 'Closed'], true);
                    ?>
                    <tr class="hover:bg-slate-50/80 transition-colors align-middle">

                      <td class="px-2 py-4 text-xs font-medium text-slate-900">
                        <?= $index + 1 ?>
                      </td>

                      <td class="px-2 py-4 text-xs font-semibold text-[#4A154B] break-words">
                        <?= e($gNumber) ?>
                      </td>

                      <td class="px-2 py-4 text-xs text-slate-700 break-words">
                        <?= e($gType) ?>
                      </td>

                      <td class="px-2 py-4 text-xs text-slate-600 whitespace-nowrap">
                        <?= e($gCreated) ?>
                      </td>

                      <td class="px-2 py-4 text-xs text-slate-700 break-words" title="<?= e($gSubject) ?>">
                        <?= e($gSubject) ?>
                      </td>

                      <td class="px-2 py-4 whitespace-nowrap">
                        <?= statusBadge($gStatus) ?>
                      </td>

                      <td class="px-2 py-4">
                        <div class="flex items-center justify-center gap-1">

                          <button type="button"
                                  title="View grievance"
                                  onclick='openViewGrievanceModal(
                                      <?= json_encode($gNumber) ?>,
                                      <?= json_encode($gType) ?>,
                                      <?= json_encode($gSubject) ?>,
                                      <?= json_encode($gDesc) ?>,
                                      <?= json_encode($gStatus) ?>,
                                      <?= json_encode($gReply) ?>,
                                      <?= json_encode($gCreated) ?>,
                                      <?= json_encode($gAttachment) ?>
                                  )'
                                  class="w-7 h-7 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                         inline-flex items-center justify-center text-[#4A154B] hover:text-white
                                         transition-all duration-200 hover:scale-110 flex-shrink-0">
                            <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                          </button>

                          <?php if ($canEdit): ?>
                            <button type="button"
                                    title="Edit grievance"
                                    onclick='openEditGrievanceModal(
                                        <?= $gId ?>,
                                        <?= $gTypeId ?>,
                                        <?= json_encode($gSubject) ?>,
                                        <?= json_encode($gDesc) ?>
                                    )'
                                    class="w-7 h-7 rounded-full bg-blue-50 hover:bg-blue-600
                                           inline-flex items-center justify-center text-blue-700 hover:text-white
                                           transition-all duration-200 hover:scale-110 flex-shrink-0">
                              <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>
                          <?php endif; ?>

                          <?php if ($canDispose): ?>
                            <button type="button"
                                    title="Dispose grievance"
                                    onclick='openDisposeModal(<?= $gId ?>, <?= json_encode($gNumber) ?>)'
                                    class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-600
                                           inline-flex items-center justify-center text-red-600 hover:text-white
                                           transition-all duration-200 hover:scale-110 flex-shrink-0">
                              <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            </button>
                          <?php endif; ?>

                        </div>
                      </td>

                      <td class="px-2 py-4 text-center">
                        <?php if ($canRemind): ?>
                          <form method="POST" action="send_reminder.php" class="inline">
                            <input type="hidden" name="grievance_id" value="<?= $gId ?>" />
                            <button type="submit"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold
                                           bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white
                                           border border-amber-200 hover:border-amber-500
                                           transition-all duration-200 hover:-translate-y-0.5 active:scale-95 whitespace-nowrap">
                              <i data-lucide="bell" class="w-3 h-3"></i>
                              <span>Reminder</span>
                            </button>
                          </form>
                        <?php elseif ($canReopen): ?>
                          <button type="button"
                                  onclick="openReopenModal(<?= $gId ?>, <?= json_encode($gNumber) ?>)"
                                  class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold
                                         bg-rose-50 hover:bg-rose-500 text-rose-700 hover:text-white
                                         border border-rose-200 hover:border-rose-500
                                         transition-all duration-200 hover:-translate-y-0.5 active:scale-95 whitespace-nowrap">
                            <i data-lucide="rotate-ccw" class="w-3 h-3"></i>
                            <span>Reopen</span>
                          </button>
                        <?php else: ?>
                          <span class="text-xs text-slate-400 italic">—</span>
                        <?php endif; ?>
                      </td>

                    </tr>
                  <?php endforeach; ?>

                <?php endif; ?>

              </tbody>
            </table>

            <!-- Footer Info & Pagination -->
            <div class="px-4 py-4 bg-slate-50/50 border-t border-slate-200
                        flex flex-col sm:flex-row items-center justify-between gap-4">

              <p class="text-sm text-slate-600" id="tableInfo">
                Showing
                <span class="font-semibold text-slate-900" id="infoStart"><?= $totalGrievances > 0 ? 1 : 0 ?></span>
                to
                <span class="font-semibold text-slate-900" id="infoEnd"><?= $totalGrievances ?></span>
                of
                <span class="font-semibold text-slate-900" id="infoTotal"><?= $totalGrievances ?></span>
                entries
              </p>

              <div class="flex items-center space-x-2">
                <button type="button" id="prevPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500
                               hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>
                  Previous
                </button>

                <span id="currentPageBadge"
                      class="inline-flex items-center justify-center w-9 h-9 rounded-lg
                             bg-[#4A154B] text-white text-sm font-bold shadow-md">
                  1
                </span>

                <button type="button" id="nextPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500
                               hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>
                  Next
                </button>
              </div>

            </div>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">

              <div class="flex items-start space-x-3">
                <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-12 w-auto" />
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
                    <span>parent.grievance@rajagiri.edu</span>
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

  <!-- ============================================================ -->
  <!-- CREATE GRIEVANCE MODAL                                        -->
  <!-- ============================================================ -->
  <div id="createGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeCreateGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in
                overflow-hidden max-h-[92vh] flex flex-col">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg md:text-xl font-bold text-slate-800">Create Grievance</h3>
        <button type="button" onclick="closeCreateGrievanceModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createGrievanceForm" method="POST" action="dashboard.php"
            enctype="multipart/form-data" class="p-6 space-y-5 overflow-y-auto flex-1">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="create_grievance" />

        <div class="space-y-2">
          <label for="grievance_type_id" class="block text-sm font-semibold text-slate-700">
            Grievance Type <span class="text-[#E5097F]">*</span>
          </label>
          <select id="grievance_type_id"
                  name="grievance_type_id"
                  required
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white
                         text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="" disabled selected>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($grievanceTypes)): ?>
            <p class="text-xs text-amber-600 font-medium mt-1">
              No active grievance types are configured. Please contact the administrator.
            </p>
          <?php endif; ?>
        </div>

        <div class="space-y-2">
          <label for="subject" class="block text-sm font-semibold text-slate-700">
            Subject <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text"
                 id="subject"
                 name="subject"
                 required
                 maxlength="120"
                 placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
          <p class="text-xs text-slate-500">
            (Maximum 120 character)
            · <span id="subjectCounter" class="font-semibold text-slate-600">0</span>/120
          </p>
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-slate-700">
            Description
          </label>
          <textarea id="description"
                    name="description"
                    rows="5"
                    maxlength="420"
                    placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
          <p class="text-xs text-slate-500">
            (Maximum 420 character)
            · <span id="descriptionCounter" class="font-semibold text-slate-600">0</span>/420
          </p>
        </div>

        <div class="space-y-2">
          <label for="attachment" class="block text-sm font-semibold text-slate-700">
            Attachment
          </label>

          <div class="flex items-center gap-3">
            <label for="attachment"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border-2 border-slate-200
                          bg-slate-50 hover:bg-slate-100 text-slate-700 font-semibold text-sm
                          cursor-pointer transition-colors">
              <i data-lucide="upload" class="w-4 h-4"></i>
              <span>Choose files</span>
            </label>

            <span id="attachmentFileName" class="text-sm text-slate-500 truncate">
              No file chosen
            </span>

            <input type="file"
                   id="attachment"
                   name="attachment"
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                   class="hidden" />
          </div>

          <p class="text-xs text-slate-500">(Max 5 Mb)</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button type="submit"
                  class="px-10 py-3 rounded-xl
                         bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                         hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                         text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                         transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                         flex items-center justify-center gap-2">
            <i data-lucide="send" class="w-4 h-4"></i>
            <span>Submit</span>
          </button>
        </div>

      </form>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- EDIT GRIEVANCE MODAL                                          -->
  <!-- ============================================================ -->
  <div id="editGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in
                overflow-hidden max-h-[92vh] flex flex-col">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg md:text-xl font-bold text-slate-800">Edit Grievance</h3>
        <button type="button" onclick="closeEditGrievanceModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editGrievanceForm" method="POST" action="dashboard.php"
            class="p-6 space-y-5 overflow-y-auto flex-1">

        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="update_grievance" />
        <input type="hidden" name="grievance_id" id="editGrievanceId" value="" />

        <div class="space-y-2">
          <label for="edit_grievance_type_id" class="block text-sm font-semibold text-slate-700">
            Grievance Type <span class="text-[#E5097F]">*</span>
          </label>
          <select id="edit_grievance_type_id"
                  name="grievance_type_id"
                  required
                  class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl appearance-none bg-white
                         text-slate-800 font-medium
                         focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                         hover:border-[#4A154B]/40 transition-all">
            <option value="" disabled>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="edit_subject" class="block text-sm font-semibold text-slate-700">
            Subject <span class="text-[#E5097F]">*</span>
          </label>
          <input type="text"
                 id="edit_subject"
                 name="subject"
                 required
                 maxlength="120"
                 placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                        placeholder-slate-400
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        hover:border-[#4A154B]/40 transition-all" />
          <p class="text-xs text-slate-500">
            (Maximum 120 character)
            · <span id="editSubjectCounter" class="font-semibold text-slate-600">0</span>/120
          </p>
        </div>

        <div class="space-y-2">
          <label for="edit_description" class="block text-sm font-semibold text-slate-700">
            Description
          </label>
          <textarea id="edit_description"
                    name="description"
                    rows="5"
                    maxlength="420"
                    placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
          <p class="text-xs text-slate-500">
            (Maximum 420 character)
            · <span id="editDescriptionCounter" class="font-semibold text-slate-600">0</span>/420
          </p>
        </div>

        <div class="pt-2 flex justify-center gap-3">
          <button type="button"
                  onclick="closeEditGrievanceModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700
                         bg-slate-100 hover:bg-slate-200 border border-slate-200
                         transition-all duration-200 active:scale-95">
            Cancel
          </button>

          <button type="submit"
                  class="px-8 py-3 rounded-xl
                         bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]
                         hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A]
                         text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                         transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                         flex items-center justify-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Changes</span>
          </button>
        </div>

      </form>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DISPOSE CONFIRMATION MODAL                                    -->
  <!-- ============================================================ -->
  <div id="disposeConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDisposeModal()"></div>

    <div id="disposeConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-red-100 to-rose-100 ring-4 ring-red-50">
          <i data-lucide="x" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Dispose Grievance?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to mark
          <span id="disposeGrievanceNumber" class="font-bold text-[#8B1E7E] break-words">this grievance</span>
          as <strong class="text-red-600">Disposed</strong>.
        </p>

        <p class="text-xs text-amber-600 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          The grievance committee will be notified.
        </p>
      </div>

      <form id="disposeForm" method="POST" action="dashboard.php"
            class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <input type="hidden" name="grievance_id" id="disposeGrievanceId" value="" />
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="dispose_grievance" />

        <button type="button"
                onclick="closeDisposeModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="submit"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-red-500 via-red-600 to-rose-600
                       hover:from-red-600 hover:via-red-700 hover:to-rose-700
                       shadow-lg shadow-red-500/30 hover:shadow-red-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="x" class="w-4 h-4"></i>
          <span>Dispose</span>
        </button>
      </form>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- VIEW GRIEVANCE MODAL (with attachment preview)                -->
  <!-- ============================================================ -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in
                overflow-hidden max-h-[90vh] flex flex-col">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Grievance Details</h3>
        <button type="button" onclick="closeViewGrievanceModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-1 space-y-5">

        <div class="flex items-start space-x-4 pb-4 border-b border-slate-100">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E]
                      flex items-center justify-center text-white shadow-md flex-shrink-0">
            <i data-lucide="file-text" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p id="vgNumber" class="text-sm font-bold text-[#4A154B] break-words">—</p>
            <p id="vgSubject" class="text-base font-bold text-slate-800 break-words mt-0.5">—</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span id="vgStatus"></span>
              <span class="text-xs text-slate-500">·</span>
              <span class="text-xs text-slate-500" id="vgDate">—</span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Grievance Type</p>
            <p id="vgType" class="text-sm font-semibold text-slate-800 break-words">—</p>
          </div>
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Submitted On</p>
            <p id="vgDate2" class="text-sm font-semibold text-slate-800">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Description</p>
          <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
            <p id="vgDescription" class="text-sm text-slate-700 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <!-- Attachment Section (with in-page preview) -->
        <div id="vgAttachmentWrapper" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Attachment</p>

          <div class="bg-purple-50 rounded-xl p-4 border border-purple-100 space-y-3">

            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-purple-900">
                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                <span id="vgAttachmentName" class="break-all">attachment</span>
              </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <button type="button" id="vgPreviewBtn"
                      onclick="openPreviewOverlay()"
                      class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold
                             bg-[#4A154B] hover:bg-[#5A1B5C] text-white
                             shadow-md shadow-purple-500/20
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                <span id="vgPreviewBtnLabel">View Image</span>
              </button>

              <a id="vgDownloadLink" href="#" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold
                        bg-white hover:bg-slate-100 text-[#4A154B]
                        border-2 border-[#4A154B]/20 hover:border-[#4A154B]/40
                        transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                <span>Download</span>
              </a>
            </div>

          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Reply / Response</p>
          <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
            <p id="vgReply" class="text-sm text-emerald-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-xl font-semibold text-slate-700
                       bg-white hover:bg-slate-100 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- IN-PAGE PREVIEW OVERLAY (sits above View modal)               -->
  <!-- ============================================================ -->
  <div id="previewOverlay" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80 backdrop-blur-md" onclick="closePreviewOverlay()"></div>

    <div class="relative w-full max-w-5xl max-h-[92vh] bg-slate-900 rounded-2xl shadow-2xl
                animate-modal-in overflow-hidden flex flex-col">

      <div class="flex items-center justify-between px-5 py-3 bg-slate-800 border-b border-slate-700">
        <div class="flex items-center gap-2 min-w-0">
          <i data-lucide="image" class="w-4 h-4 text-purple-300 flex-shrink-0"></i>
          <p id="previewFileName" class="text-sm font-semibold text-slate-100 truncate">Attachment</p>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <a id="previewOpenNewTab" href="#" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold
                    bg-slate-700 hover:bg-slate-600 text-slate-100 transition-colors">
            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            <span>Open in new tab</span>
          </a>
          <button type="button" onclick="closePreviewOverlay()"
                  class="w-8 h-8 rounded-lg bg-slate-700 hover:bg-red-500
                         flex items-center justify-center text-slate-100 transition-colors"
                  aria-label="Close preview">
            <i data-lucide="x" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-auto bg-black/40 flex items-center justify-center p-4">
        <img id="previewImage" src="" alt="Attachment preview"
             class="max-w-full max-h-[80vh] object-contain rounded-lg shadow-2xl hidden" />
        <iframe id="previewFrame" src="" title="Attachment preview"
                class="w-full h-[80vh] rounded-lg bg-white hidden"></iframe>
      </div>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- REOPEN CONFIRMATION MODAL                                     -->
  <!-- ============================================================ -->
  <div id="reopenConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeReopenModal()"></div>

    <div id="reopenConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-gradient-to-br from-rose-100 to-pink-100 ring-4 ring-rose-50">
          <i data-lucide="rotate-ccw" class="w-8 h-8 text-rose-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Reopen Grievance?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to reopen
          <span id="reopenGrievanceNumber" class="font-bold text-[#8B1E7E] break-words">this grievance</span>.
          It will be sent back to the grievance committee for review.
        </p>

        <p class="text-xs text-amber-600 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          Only reopen if the issue is not resolved.
        </p>
      </div>

      <form id="reopenForm" method="POST" action="reopen_grievance.php"
            class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <input type="hidden" name="grievance_id" id="reopenGrievanceId" value="" />
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

        <button type="button"
                onclick="closeReopenModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700
                       bg-slate-100 hover:bg-slate-200 border border-slate-200
                       transition-all duration-200 active:scale-95">
          Cancel
        </button>

        <button type="submit"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white
                       bg-gradient-to-r from-rose-500 via-rose-600 to-pink-600
                       hover:from-rose-600 hover:via-rose-700 hover:to-pink-700
                       shadow-lg shadow-rose-500/30 hover:shadow-rose-500/50
                       transition-all duration-300 hover:-translate-y-0.5 active:scale-95
                       flex items-center justify-center gap-2">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          <span>Reopen</span>
        </button>
      </form>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM LOGOUT CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
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

    // ---- Auto-dismiss flash messages after 3 seconds ----
    (function () {
      ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
        const box = document.getElementById(id);
        if (!box) return;
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');
          setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
        }, 3000);
      });
    })();

    // ============================================================
    // SIDEBAR EXPAND / COLLAPSE
    // ============================================================
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('parentSidebar');
      const main      = document.getElementById('parentMain');
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

        setTimeout(function () {
          if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 250);
      });
    })();

    // ============================================================
    // PARENT PROFILE DROPDOWN
    // ============================================================
    (function () {
      const btn       = document.getElementById('parent-dropdown-btn');
      const menu      = document.getElementById('parent-dropdown-menu');
      const chevron   = document.getElementById('parent-chevron');
      const container = document.getElementById('parent-dropdown-container');

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
    // CREATE GRIEVANCE MODAL
    // ============================================================
    const createGrievanceModal = document.getElementById('createGrievanceModal');
    const createGrievanceForm  = document.getElementById('createGrievanceForm');
    const subjectInput         = document.getElementById('subject');
    const descriptionInput     = document.getElementById('description');
    const subjectCounter       = document.getElementById('subjectCounter');
    const descriptionCounter   = document.getElementById('descriptionCounter');
    const attachmentInput      = document.getElementById('attachment');
    const attachmentFileName   = document.getElementById('attachmentFileName');

    function openCreateGrievanceModal() {
      if (!createGrievanceModal) return;

      if (createGrievanceForm) createGrievanceForm.reset();
      if (subjectCounter)     subjectCounter.textContent = '0';
      if (descriptionCounter) descriptionCounter.textContent = '0';
      if (attachmentFileName) attachmentFileName.textContent = 'No file chosen';

      createGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      setTimeout(function () {
        const firstField = document.getElementById('grievance_type_id');
        if (firstField) firstField.focus();
      }, 60);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeCreateGrievanceModal() {
      if (!createGrievanceModal) return;
      createGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    if (subjectInput && subjectCounter) {
      subjectInput.addEventListener('input', function () {
        subjectCounter.textContent = String(this.value.length);
        subjectCounter.classList.toggle('text-red-600', this.value.length > 120);
      });
    }

    if (descriptionInput && descriptionCounter) {
      descriptionInput.addEventListener('input', function () {
        descriptionCounter.textContent = String(this.value.length);
        descriptionCounter.classList.toggle('text-red-600', this.value.length > 420);
      });
    }

    if (attachmentInput && attachmentFileName) {
      attachmentInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) {
          attachmentFileName.textContent = 'No file chosen';
          attachmentFileName.classList.remove('text-red-600');
          return;
        }

        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) {
          attachmentFileName.textContent = file.name + ' — exceeds 5 MB limit';
          attachmentFileName.classList.add('text-red-600');
          this.value = '';
          return;
        }

        attachmentFileName.classList.remove('text-red-600');
        attachmentFileName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
      });
    }

    // ============================================================
    // EDIT GRIEVANCE MODAL
    // ============================================================
    const editGrievanceModal    = document.getElementById('editGrievanceModal');
    const editGrievanceForm     = document.getElementById('editGrievanceForm');
    const editGrievanceId       = document.getElementById('editGrievanceId');
    const editTypeSelect        = document.getElementById('edit_grievance_type_id');
    const editSubjectInput      = document.getElementById('edit_subject');
    const editDescriptionInput  = document.getElementById('edit_description');
    const editSubjectCounter    = document.getElementById('editSubjectCounter');
    const editDescriptionCounter= document.getElementById('editDescriptionCounter');

    function openEditGrievanceModal(id, typeId, subject, description) {
      if (!editGrievanceModal) return;

      editGrievanceId.value = String(id);
      editTypeSelect.value  = String(typeId);
      editSubjectInput.value = subject || '';
      editDescriptionInput.value = description || '';

      editSubjectCounter.textContent = String((subject || '').length);
      editDescriptionCounter.textContent = String((description || '').length);

      editSubjectCounter.classList.toggle('text-red-600', (subject || '').length > 120);
      editDescriptionCounter.classList.toggle('text-red-600', (description || '').length > 420);

      editGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      setTimeout(function () { if (editSubjectInput) editSubjectInput.focus(); }, 60);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeEditGrievanceModal() {
      if (!editGrievanceModal) return;
      editGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      if (editGrievanceForm) editGrievanceForm.reset();
    }

    if (editSubjectInput && editSubjectCounter) {
      editSubjectInput.addEventListener('input', function () {
        editSubjectCounter.textContent = String(this.value.length);
        editSubjectCounter.classList.toggle('text-red-600', this.value.length > 120);
      });
    }

    if (editDescriptionInput && editDescriptionCounter) {
      editDescriptionInput.addEventListener('input', function () {
        editDescriptionCounter.textContent = String(this.value.length);
        editDescriptionCounter.classList.toggle('text-red-600', this.value.length > 420);
      });
    }

    // ============================================================
    // DISPOSE CONFIRMATION MODAL
    // ============================================================
    const disposeConfirmModal = document.getElementById('disposeConfirmModal');
    const disposeConfirmPanel = document.getElementById('disposeConfirmPanel');
    const disposeGrievanceId  = document.getElementById('disposeGrievanceId');
    const disposeGrievanceNum = document.getElementById('disposeGrievanceNumber');

    function openDisposeModal(grievanceId, grievanceNumber) {
      if (!disposeConfirmModal) return;

      disposeGrievanceId.value = String(grievanceId);
      disposeGrievanceNum.textContent = '"' + (grievanceNumber || '') + '"';

      disposeConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (disposeConfirmPanel) {
        disposeConfirmPanel.classList.remove('animate-confirm-shake');
        void disposeConfirmPanel.offsetWidth;
        disposeConfirmPanel.classList.add('animate-confirm-shake');
      }

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDisposeModal() {
      if (!disposeConfirmModal) return;
      disposeConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // VIEW GRIEVANCE MODAL + IN-PAGE PREVIEW
    // ============================================================
    const viewGrievanceModal = document.getElementById('viewGrievanceModal');
    const previewOverlay     = document.getElementById('previewOverlay');
    const previewImage       = document.getElementById('previewImage');
    const previewFrame       = document.getElementById('previewFrame');
    const previewFileName    = document.getElementById('previewFileName');
    const previewOpenNewTab  = document.getElementById('previewOpenNewTab');

    // Currently loaded attachment path (relative to parent/)
    let currentAttachmentPath = '';
    let currentAttachmentUrl  = '';
    let currentAttachmentExt  = '';
    let currentAttachmentName = '';

    function statusBadgeHtml(status) {
      const s = (status || '').trim();
      const map = {
        'Pending':     'bg-amber-100 text-amber-800 border-amber-200',
        'In Progress': 'bg-blue-100 text-blue-800 border-blue-200',
        'Disposed':    'bg-emerald-100 text-emerald-800 border-emerald-200',
        'Closed':      'bg-slate-200 text-slate-700 border-slate-300',
        'Reopened':    'bg-rose-100 text-rose-800 border-rose-200'
      };
      const cls = map[s] || 'bg-slate-100 text-slate-700 border-slate-200';
      return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' + cls + '">' + s + '</span>';
    }

    function isImageExt(ext) {
      return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].indexOf(ext) !== -1;
    }
    function isPdfExt(ext) {
      return ext === 'pdf';
    }

    function openViewGrievanceModal(number, type, subject, description, status, reply, date, attachment) {
      document.getElementById('vgNumber').textContent     = number || '—';
      document.getElementById('vgSubject').textContent    = subject || '—';
      document.getElementById('vgStatus').innerHTML       = statusBadgeHtml(status);
      document.getElementById('vgType').textContent       = type || '—';
      document.getElementById('vgDate').textContent       = date || '—';
      document.getElementById('vgDate2').textContent      = date || '—';
      document.getElementById('vgDescription').textContent = (description && description.trim() !== '') ? description : 'No description provided.';
      document.getElementById('vgReply').textContent      = (reply && reply.trim() !== '') ? reply : 'No response yet from the grievance committee.';

      // ---- Attachment ----
      const attWrapper    = document.getElementById('vgAttachmentWrapper');
      const attName       = document.getElementById('vgAttachmentName');
      const attPreviewBtn = document.getElementById('vgPreviewBtn');
      const attPreviewLbl = document.getElementById('vgPreviewBtnLabel');
      const attDownload   = document.getElementById('vgDownloadLink');

      const attPath = (attachment || '').trim();

      if (attPath !== '' && attWrapper) {
        // parent/ is one level below project root → prefix with ../
        const cleaned = attPath.replace(/^\/+/, '');
        const url     = '../' + cleaned;

        const parts = cleaned.split('/');
        const fname = parts[parts.length - 1] || 'attachment';
        const ext   = (fname.split('.').pop() || '').toLowerCase();

        currentAttachmentPath = cleaned;
        currentAttachmentUrl  = url;
        currentAttachmentExt  = ext;
        currentAttachmentName = fname;

        attName.textContent  = fname;
        attDownload.href     = url;

        if (isImageExt(ext)) {
          attPreviewLbl.textContent = 'View Image';
          attPreviewBtn.classList.remove('hidden');
        } else if (isPdfExt(ext)) {
          attPreviewLbl.textContent = 'View PDF';
          attPreviewBtn.classList.remove('hidden');
        } else {
          attPreviewBtn.classList.add('hidden');
        }

        attWrapper.classList.remove('hidden');
      } else {
        currentAttachmentPath = '';
        currentAttachmentUrl  = '';
        currentAttachmentExt  = '';
        currentAttachmentName = '';
        if (attWrapper) attWrapper.classList.add('hidden');
      }

      viewGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewGrievanceModal() {
      if (previewOverlay && !previewOverlay.classList.contains('hidden')) {
        closePreviewOverlay();
      }
      viewGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ---- In-page preview overlay ----
    function openPreviewOverlay() {
      if (!currentAttachmentUrl) return;

      previewFileName.textContent = currentAttachmentName || 'Attachment';
      previewOpenNewTab.href      = currentAttachmentUrl;

      if (isImageExt(currentAttachmentExt)) {
        previewImage.src = currentAttachmentUrl;
        previewImage.classList.remove('hidden');
        previewFrame.classList.add('hidden');
        previewFrame.src = '';
      } else if (isPdfExt(currentAttachmentExt)) {
        previewFrame.src = currentAttachmentUrl;
        previewFrame.classList.remove('hidden');
        previewImage.classList.add('hidden');
        previewImage.src = '';
      } else {
        window.open(currentAttachmentUrl, '_blank', 'noopener');
        return;
      }

      previewOverlay.classList.remove('hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closePreviewOverlay() {
      if (!previewOverlay) return;
      previewOverlay.classList.add('hidden');
      if (previewImage) { previewImage.src = ''; }
      if (previewFrame) { previewFrame.src = ''; }
    }

    // ============================================================
    // REOPEN CONFIRMATION MODAL
    // ============================================================
    const reopenConfirmModal = document.getElementById('reopenConfirmModal');
    const reopenConfirmPanel = document.getElementById('reopenConfirmPanel');

    function openReopenModal(grievanceId, grievanceNumber) {
      document.getElementById('reopenGrievanceId').value = grievanceId;
      document.getElementById('reopenGrievanceNumber').textContent = '"' + (grievanceNumber || '') + '"';

      reopenConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (reopenConfirmPanel) {
        reopenConfirmPanel.classList.remove('animate-confirm-shake');
        void reopenConfirmPanel.offsetWidth;
        reopenConfirmPanel.classList.add('animate-confirm-shake');
      }

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeReopenModal() {
      reopenConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    // ============================================================
    // LOGOUT CONFIRMATION MODAL
    // ============================================================
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');

    const LOGOUT_URL = '../logout.php?role=parent';

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

    // ============================================================
    // LIVE SEARCH + ENTRIES PER PAGE (client-side)
    // ============================================================
    (function () {
      const searchInput   = document.getElementById('searchInput');
      const entriesSelect = document.getElementById('entriesPerPage');
      const tableBody     = document.getElementById('grievancesTableBody');
      const infoStart     = document.getElementById('infoStart');
      const infoEnd       = document.getElementById('infoEnd');
      const infoTotal     = document.getElementById('infoTotal');
      const prevBtn       = document.getElementById('prevPageBtn');
      const nextBtn       = document.getElementById('nextPageBtn');
      const pageBadge     = document.getElementById('currentPageBadge');

      if (!tableBody) return;

      const allRows = Array.from(tableBody.querySelectorAll('tr')).filter(function (r) {
        return !r.querySelector('td[colspan]');
      });

      let pageSize     = 10;
      let currentPage  = 1;
      let searchTerm   = '';

      function applyFilters() {
        const filtered = allRows.filter(function (row) {
          if (searchTerm === '') return true;
          return row.textContent.toLowerCase().indexOf(searchTerm) !== -1;
        });

        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;

        allRows.forEach(function (r) { r.style.display = 'none'; });

        const startIdx = (currentPage - 1) * pageSize;
        const endIdx   = Math.min(startIdx + pageSize, filtered.length);

        filtered.slice(startIdx, endIdx).forEach(function (r) { r.style.display = ''; });

        if (infoStart) infoStart.textContent = filtered.length === 0 ? 0 : startIdx + 1;
        if (infoEnd)   infoEnd.textContent   = endIdx;
        if (infoTotal) infoTotal.textContent = filtered.length;

        if (prevBtn) prevBtn.disabled = (currentPage <= 1);
        if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);
        if (pageBadge) pageBadge.textContent = currentPage;
      }

      if (searchInput) {
        let timer = null;
        searchInput.addEventListener('input', function () {
          clearTimeout(timer);
          const self = this;
          timer = setTimeout(function () {
            searchTerm = self.value.toLowerCase().trim();
            currentPage = 1;
            applyFilters();
          }, 200);
        });
      }

      if (entriesSelect) {
        entriesSelect.addEventListener('change', function () {
          pageSize = parseInt(this.value, 10) || 10;
          currentPage = 1;
          applyFilters();
        });
      }

      if (prevBtn) {
        prevBtn.addEventListener('click', function () {
          if (currentPage > 1) { currentPage--; applyFilters(); }
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener('click', function () {
          currentPage++;
          applyFilters();
        });
      }

      applyFilters();
    })();

    // ============================================================
    // ESCAPE KEY CLOSES ANY OPEN MODAL (preview first)
    // ============================================================
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;

      if (previewOverlay && !previewOverlay.classList.contains('hidden')) {
        closePreviewOverlay();
        return;
      }

      if (createGrievanceModal && !createGrievanceModal.classList.contains('hidden')) closeCreateGrievanceModal();
      if (editGrievanceModal && !editGrievanceModal.classList.contains('hidden')) closeEditGrievanceModal();
      if (disposeConfirmModal && !disposeConfirmModal.classList.contains('hidden')) closeDisposeModal();
      if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) closeViewGrievanceModal();
      if (reopenConfirmModal && !reopenConfirmModal.classList.contains('hidden')) closeReopenModal();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>