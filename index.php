<?php
// ป้องกัน Warning/Notice หลุดไปปะปนกับ JSON
ob_start();

// 1. ตรวจสอบสิทธิ์ (ต้องเรียกก่อนการเชื่อมต่อฐานข้อมูล หากต้องการใช้ฐานข้อมูลใน auth.php)
require_once 'auth.php';
//require_login(); // บังคับ Login ก่อนเข้าถึงหน้านี้

// 2. เชื่อมต่อฐานข้อมูล (ต้องมีไฟล์ config.php อยู่ในโฟลเดอร์เดียวกัน)
require_once 'config.php';

// ถ้าผู้ใช้เข้าสู่ระบบอยู่แล้ว ให้ส่งไปหน้า list.php ทันที
if (is_logged_in()) {
    if (ob_get_length()) ob_clean();
    header('Location: list.php');
    exit();
}

$error_message = '';
$message = '';
$username = '';

// ตรวจสอบการส่งข้อมูลสมัครสมาชิกผ่าน AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    // ล้าง Buffer ทั้งหมดก่อนส่ง JSON ออกไป
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => ''];

    $firstname        = trim($_POST['firstname'] ?? '');
    $lastname         = trim($_POST['lastname'] ?? '');
    $position         = trim($_POST['position'] ?? '');
    $organization     = trim($_POST['organization'] ?? '');
    $phone            = trim($_POST['phone'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $line_id          = trim($_POST['line_id'] ?? '');
    $reg_username     = trim($_POST['username'] ?? '');
    $reg_password     = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms            = isset($_POST['terms']) ? true : false;

    // Validation พื้นฐาน
    if (empty($firstname) || empty($lastname) || empty($reg_username) || empty($reg_password) || empty($email)) {
        $response['message'] = '❌ กรุณากรอกข้อมูลที่มีเครื่องหมาย (*) ให้ครบถ้วน';
        echo json_encode($response);
        exit();
    }

    if (!$terms) {
        $response['message'] = '❌ กรุณายอมรับเงื่อนไขการใช้งานระบบ';
        echo json_encode($response);
        exit();
    }

    if ($reg_password !== $confirm_password) {
        $response['message'] = '❌ รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
        echo json_encode($response);
        exit();
    }

    // Password validation: อย่างน้อย 8 ตัว, พิมพ์ใหญ่, พิมพ์เล็ก, ตัวเลข
    if (strlen($reg_password) < 8 || !preg_match('/[A-Z]/', $reg_password) || !preg_match('/[a-z]/', $reg_password) || !preg_match('/[0-9]/', $reg_password)) {
        $response['message'] = '❌ รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร และต้องประกอบด้วยตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข';
        echo json_encode($response);
        exit();
    }

    try {
        // ตรวจสอบ Username ซ้ำ
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$reg_username]);
        if ($stmt->fetch()) {
            $response['message'] = '❌ ชื่อผู้ใช้งาน (Username) นี้ถูกใช้งานแล้วในระบบ';
            echo json_encode($response);
            exit();
        }

        // ตรวจสอบ Email ซ้ำ
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $response['message'] = '❌ อีเมล (Email) นี้ถูกใช้งานแล้วในระบบ';
            echo json_encode($response);
            exit();
        }

        // จัดการอัปโหลดรูปโปรไฟล์
        $profile_image = null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $file_type = mime_content_type($file['tmp_name']);

            if (!in_array($file_type, $allowed_types)) {
                $response['message'] = '❌ รองรับเฉพาะไฟล์รูปภาพนามสกุล JPG, PNG และ WEBP เท่านั้น';
                echo json_encode($response);
                exit();
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                $response['message'] = '❌ ขนาดไฟล์รูปภาพต้องไม่เกิน 2MB';
                echo json_encode($response);
                exit();
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $upload_path = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $profile_image = $filename;
            } else {
                $response['message'] = '❌ ไม่สามารถอัปโหลดรูปภาพได้ กรุณาลองใหม่อีกครั้ง';
                echo json_encode($response);
                exit();
            }
        }

        // Hash Password ด้วย password_hash()
        $hashed_password = password_hash($reg_password, PASSWORD_DEFAULT);

        // บันทึกลงฐานข้อมูล
        $sql = "INSERT INTO users (username, password, firstname, lastname, position, organization, phone, email, line_id, profile_image, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $reg_username,
            $hashed_password,
            $firstname,
            $lastname,
            $position,
            $organization,
            $phone,
            $email,
            $line_id,
            $profile_image
        ]);

        $response['success'] = true;
        $response['message'] = 'ลงทะเบียนสำเร็จแล้ว! กรุณาเข้าสู่ระบบ';
        echo json_encode($response);
        exit();

    } catch (PDOException $e) {
        $response['message'] = '❌ ข้อผิดพลาดฐานข้อมูล: ' . $e->getMessage();
        echo json_encode($response);
        exit();
    }
}

// ปิด Buffer ส่วนที่ไม่ใช่ AJAX เพื่อทำงานต่อปกติ
if (ob_get_length()) ob_end_flush();

// ตรวจสอบพารามิเตอร์ URL สำหรับข้อความแจ้งเตือน (Login ปกติ)
if (isset($_GET['expired'])) {
    $error_message = '⚠️ เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง';
} elseif (isset($_GET['logout'])) {
    $message = '✅ ออกจากระบบเรียบร้อยแล้ว';
} elseif (isset($_GET['message']) && $_GET['message'] === 'register_success') {
    $message = '✅ ลงทะเบียนสำเร็จแล้ว! กรุณาเข้าสู่ระบบ';
}

// 2. จัดการการส่งฟอร์ม Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error_message = '❌ กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน';
    } else {
        try {
            // A. ค้นหาผู้ใช้จากฐานข้อมูล
            $sql = "SELECT id, username, password, firstname AS name, role FROM users WHERE username = :username";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // B. ตรวจสอบรหัสผ่านที่แฮชไว้
                if (password_verify($password, $user['password'])) {
                    // C. Login สำเร็จ: ตั้งค่า Session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['LAST_ACTIVITY'] = time();

                    // D. Redirect ไปยังหน้าเดิมที่ต้องการเข้าถึง หรือ list.php
                    $redirect_url = $_SESSION['redirect_url'] ?? 'list.php';
                    unset($_SESSION['redirect_url']);
                    header("Location: $redirect_url");
                    exit();
                } else {
                    $error_message = '❌ รหัสผ่านไม่ถูกต้อง';
                }
            } else {
                $error_message = '❌ ไม่พบชื่อผู้ใช้ในระบบ';
            }
        } catch (PDOException $e) {
            $error_message = '❌ ข้อผิดพลาดในการเชื่อมต่อ: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบพิมพ์บัตรประจำตัวเจ้าหน้าที่รัฐ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- SweetAlert2 & jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        body {
            box-sizing: border-box;
            font-family: 'Prompt', sans-serif;
        }
        
        .bg-custom-image {
            background-image: linear-gradient(rgba(15, 23, 42, 0.75), rgba(15, 23, 42, 0.75)), url('Image.png');
            background-size: cover;
            background-position: center;
        }
        
        .card-shadow {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -5px rgba(30, 64, 175, 0.3);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="h-full bg-slate-900 bg-custom-image min-h-screen flex items-center justify-center">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            
            <!-- ฝั่งซ้าย: ข้อความต้อนรับและรายละเอียดระบบ -->
            <div class="text-center lg:text-left text-white">
                <div class="space-y-4">
                    <h2 class="text-2xl lg:text-3xl font-light text-blue-200 drop-shadow-md">
                        ยินดีต้อนรับสู่ระบบ
                    </h2>
                    <h1 class="text-4xl lg:text-5xl font-bold text-white drop-shadow-lg leading-tight">
                        พิมพ์บัตรประจำตัว<br>
                        <span class="text-blue-400">เจ้าหน้าที่รัฐ</span>
                    </h1>
                    <p class="text-blue-100 text-lg lg:text-xl font-normal drop-shadow pt-2">
                        สะดวกรวดเร็ว แม่นยำ และได้มาตรฐาน
                    </p>
                </div>
            </div>
            
            <!-- ฝั่งขวา: ฟอร์มเข้าสู่ระบบ (Login) -->
            <div class="flex justify-center">
                <div class="bg-white/95 backdrop-blur-md rounded-2xl card-shadow p-8 max-w-md w-full border border-blue-100">
                    <div class="text-center mb-6">
                        <h3 class="text-2xl font-bold text-blue-900 mb-1">🔑 เข้าสู่ระบบ</h3>
                        <p class="text-xs text-gray-500 mb-4">กรุณาใช้บัญชีผู้ใช้งานเพื่อเข้าสู่การจัดการข้อมูล</p>
                    </div>

                    <?php if ($error_message): ?>
                        <div class='p-3 mb-4 text-sm text-red-700 bg-red-100 rounded-lg border border-red-300' role='alert'>
                            <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($message): ?>
                        <div class='p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg border border-green-300' role='alert'>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="space-y-4">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">ชื่อผู้ใช้ (Username)</label>
                            <input type="text" id="username" name="username" required 
                                   value="<?= htmlspecialchars($username) ?>"
                                   class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 transition duration-150 text-gray-800"
                                   placeholder="">
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">รหัสผ่าน (Password)</label>
                            <input type="password" id="password" name="password" required 
                                   class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 transition duration-150 text-gray-800"
                                   placeholder="">
                        </div>

                        <div class="pt-2">
                            <button type="submit" 
                                    class="btn-primary w-full flex justify-center py-3 px-4 border border-transparent 
                                           rounded-xl shadow-sm text-lg font-semibold text-white 
                                           focus:outline-none focus:ring-2 focus:ring-offset-2 
                                           focus:ring-blue-500 transition duration-150">
                                เข้าสู่ระบบ
                            </button>
                        </div>
                    </form>
                    
                    <p class="mt-6 text-center text-xs text-gray-500">
                        หากพบปัญหาในการเข้าสู่ระบบ <a href="#" id="openQrModal" class="text-blue-600 hover:text-blue-800 hover:underline font-medium transition duration-150">กรุณาติดต่อผู้ดูแลระบบ</a>
                    </p>
                   
                    <p class="mt-4 text-center text-sm text-gray-600">
                        ยังไม่มีบัญชี? 
                        <a href="#" id="openRegisterModal" class="font-medium text-blue-600 hover:text-blue-500 transition duration-150">
                            ลงทะเบียนใช้งานที่นี่
                        </a>
                    </p>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal ลงทะเบียนใช้งาน -->
    <div id="registerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm hidden px-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden border border-blue-100 transform transition-all duration-300 scale-95 opacity-0" id="modalContainer">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-700 to-indigo-800 text-white px-6 py-4 flex justify-between items-center">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    📝 ลงทะเบียนใช้งานระบบใหม่
                </h3>
                <button type="button" id="closeModalBtn" class="text-white/80 hover:text-white text-2xl font-bold focus:outline-none">&times;</button>
            </div>

            <!-- Modal Body -->
            <div class="p-6 max-h-[75vh] overflow-y-auto custom-scrollbar">
                <form id="registerForm" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="register">
                    
                    <!-- ส่วนแสดงตัวอย่างรูปภาพ (Image Preview) สไตล์ edit_profile -->
                    <div class="flex flex-col sm:flex-row items-center gap-4 p-3 bg-slate-50 rounded-xl border border-slate-200">
                        <div class="shrink-0">
                            <img id="profileImagePreview" src="https://ui-avatars.com/api/?name=User&background=cbd5e1&color=fff&size=128" alt="ตัวอย่างรูปโปรไฟล์" class="w-20 h-20 rounded-full object-cover border-2 border-blue-100 shadow-sm">
                        </div>
                        <div class="flex-1 space-y-1 w-full text-center sm:text-left">
                            <label class="block text-xs font-semibold text-gray-700">รูปโปรไฟล์ (JPG, PNG, WEBP / Max 2MB)</label>
                            <input type="file" id="profile_image_input" name="profile_image" accept="image/jpeg,image/png,image/webp" class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer">
                            <p id="profileImageName" class="text-[11px] text-gray-400 truncate max-w-[200px]"></p>
                        </div>
                    </div>

                    <hr class="border-gray-200 my-2"> <!-- ขีดเส้นสีเทา -->

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">ชื่อ <span class="text-red-500">*</span></label>
                            <input type="text" name="firstname" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">นามสกุล <span class="text-red-500">*</span></label>
                            <input type="text" name="lastname" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">ตำแหน่ง</label>
                            <input type="text" name="position" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="เช่น นักทรัพยากรบุคคล">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">หน่วยงาน</label>
                            <input type="text" name="organization" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="เช่น เทศบาล...">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">เบอร์โทรศัพท์</label>
                            <input type="tel" name="phone" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0812345678">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="example@email.com">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">ID Line</label>
                            <input type="text" name="line_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Line ID">
                        </div>
                    </div>

                    <hr class="border-gray-200 my-2">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                            <input type="text" name="username" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="ชื่อผู้ใช้สำหรับเข้าสู่ระบบ">
                        </div>
                        
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
						<div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Password (ต้องมีความยาวอย่างน้อย 8 ตัวอักษร) <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="password" id="reg_password" required class="w-full px-3 py-2 pr-10 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="พิมพ์ใหญ่, เล็ก, ตัวเลข">
                                <button type="button" id="toggleRegPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none">
                                    <svg id="eyeIconReg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                            <div class="relative">
                                <input type="password" name="confirm_password" id="confirm_password" required class="w-full px-3 py-2 pr-10 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="ยืนยันรหัสผ่านอีกครั้ง">
                                <button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none">
                                    <svg id="eyeIconConfirm" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
					</div>
					<div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
					<div class="pt-2 md:pt-5">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" name="terms" required class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <span class="ml-2 text-xs text-gray-700 font-medium">ยอมรับเงื่อนไขการใช้งานระบบ <span class="text-red-500">*</span></span>
                            </label>
                        </div>
                    </div>

                    <!-- Modal Footer Buttons -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 mt-4">
                        
                        <button type="submit" id="submitRegisterBtn" class="px-6 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition shadow-md">ยืนยันสมัครสมาชิก</button>
						<button type="button" id="cancelModalBtn" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-300 transition">ยกเลิก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript ควบคุม Modal และ AJAX Registration พร้อมระบบดักจับ Error ที่ละเอียดยิ่งขึ้น -->
    <script>
        $(document).ready(function() {
            const $modal = $('#registerModal');
            const $modalContainer = $('#modalContainer');

            // SVG Icons สำหรับตาเปิดและตาปิด
            const eyeOpenSvg = `<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>`;
            const eyeClosedSvg = `<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.032 10.032 0 012.238-3.792m3.674-2.222A9.97 9.97 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.027 10.027 0 01-4.132 5.411m0 0L21 21" /></svg>`;

            // แสดง QR Code สำหรับติดต่อผู้ดูแลระบบเมื่อกดลิงก์
            $('#openQrModal').on('click', function(e) {
                e.preventDefault();
                Swal.fire({
                    title: 'ติดต่อผู้ดูแลระบบ',
                    text: 'สแกน QR Code ด้านล่างเพื่อติดต่อผ่าน Line',
                    imageUrl: 'Qr Code Line.jpg',
                    imageWidth: 220,
                    imageHeight: 220,
                    imageAlt: 'QR Code Line ติดต่อผู้ดูแลระบบ',
                    confirmButtonText: 'ปิด',
                    confirmButtonColor: '#2563eb'
                });
            });

            function openModal() {
                $modal.removeClass('hidden');
                setTimeout(() => {
                    $modalContainer.removeClass('scale-95 opacity-0').addClass('scale-100 opacity-100');
                }, 10);
            }

            function closeModal() {
                $modalContainer.removeClass('scale-100 opacity-100').addClass('scale-95 opacity-0');
                setTimeout(() => {
                    $modal.addClass('hidden');
                    $('#registerForm')[0].reset();
                    // รีเซ็ตสถานะรูปโปรไฟล์ตัวอย่างเป็นค่าเริ่มต้นเหมือน edit_profile
                    $('#profileImagePreview').attr('src', 'https://ui-avatars.com/api/?name=User&background=cbd5e1&color=fff&size=128');
                    $('#profileImageName').text('');
                    // รีเซ็ตสถานะช่องรหัสผ่านและไอคอนตกลับเป็นค่าเริ่มต้น
                    $('#reg_password, #confirm_password').attr('type', 'password');
                    $('#toggleRegPassword').html(eyeOpenSvg);
                    $('#toggleConfirmPassword').html(eyeOpenSvg);
                }, 300);
            }

            // แสดงตัวอย่างรูปภาพเมื่อมีการเลือกไฟล์ (ใช้ FileReader เหมือน edit_profile)
            $('#profile_image_input').on('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        $('#profileImagePreview').attr('src', evt.target.result);
                        $('#profileImageName').text(file.name);
                    };
                    reader.readAsDataURL(file);
                } else {
                    $('#profileImagePreview').attr('src', 'https://ui-avatars.com/api/?name=User&background=cbd5e1&color=fff&size=128');
                    $('#profileImageName').text('');
                }
            });

            $('#openRegisterModal').on('click', function(e) {
                e.preventDefault();
                openModal();
            });

            $('#closeModalBtn, #cancelModalBtn').on('click', function() {
                closeModal();
            });

            $modal.on('click', function(e) {
                if ($(e.target).is($modal)) {
                    closeModal();
                }
            });

            // ฟังก์ชันควบคุมการสลับแสดง/ซ่อนรหัสผ่าน (Password)
            $('#toggleRegPassword').on('click', function() {
                const $input = $('#reg_password');
                const isPassword = $input.attr('type') === 'password';
                $input.attr('type', isPassword ? 'text' : 'password');
                $(this).html(isPassword ? eyeClosedSvg : eyeOpenSvg);
            });

            // ฟังก์ชันควบคุมการสลับแสดง/ซ่อนรหัสผ่าน (Confirm Password)
            $('#toggleConfirmPassword').on('click', function() {
                const $input = $('#confirm_password');
                const isPassword = $input.attr('type') === 'password';
                $input.attr('type', isPassword ? 'text' : 'password');
                $(this).html(isPassword ? eyeClosedSvg : eyeOpenSvg);
            });

            $('#registerForm').on('submit', function(e) {
                e.preventDefault();

                const password = $('#reg_password').val();
                const confirmPassword = $('#confirm_password').val();

                if (password !== confirmPassword) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'รหัสผ่านไม่ตรงกัน',
                        text: 'กรุณากรอกรหัสผ่านและการยืนยันรหัสผ่านให้ตรงกัน',
                        confirmButtonColor: '#2563eb'
                    });
                    return;
                }

                const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
                if (!passwordRegex.test(password)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'รหัสผ่านไม่ปลอดภัย',
                        text: 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร และประกอบด้วยตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข',
                        confirmButtonColor: '#2563eb'
                    });
                    return;
                }

                const formData = new FormData(this);

                $.ajax({
                    url: '',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'ลงทะเบียนสำเร็จ!',
                                text: response.message,
                                confirmButtonColor: '#2563eb'
                            }).then(() => {
                                closeModal();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: response.message,
                                confirmButtonColor: '#2563eb'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error Response:", xhr.responseText);
                        Swal.fire({
                            icon: 'error',
                            title: 'ข้อผิดพลาดระบบ',
                            text: 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้ กรุณาลองใหม่อีกครั้ง (ตรวจสอบ Console เพื่อดูรายละเอียด Error ทางฝั่ง PHP)',
                            confirmButtonColor: '#2563eb'
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>