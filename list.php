<?php
// list.php

// 1. ตรวจสอบสิทธิ์
require_once 'auth.php';
require_login(); // ตรวจสอบว่ามีการ login หรือไม่
// 2. เชื่อมต่อฐานข้อมูล
require_once 'config.php';

// 💡 ดึง User ID ของผู้ใช้ปัจจุบัน
$user_id = $_SESSION['user_id'] ?? 0;

// 💡 ดึง Role และข้อมูลชื่อ-นามสกุลของผู้ใช้ที่ล็อกอินอยู่จากฐานข้อมูล
$is_admin = false;
$current_user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'ผู้ใช้งาน';

if (is_logged_in() && $user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT role, firstname, lastname FROM users WHERE id = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['role'] === 'admin' || ($_SESSION['role'] ?? '') === 'admin') {
                $is_admin = true;
            }
            
            $fname = trim($user['firstname'] ?? '');
            $lname = trim($user['lastname'] ?? '');
            if (!empty($fname) || !empty($lname)) {
                $current_user_name = trim("$fname $lname");
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching user info: " . $e->getMessage());
    }
}
// --------------------------------------------------------------------


// 1. ดึงสถานะการตั้งค่าปุ่มต่างๆ
$button_settings = [];
$error_message = ''; // กำหนดค่าเริ่มต้น
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $all_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ดึงมาเป็น array [key => value]

        $button_settings['BTN_EXPORT'] = $all_settings['BTN_EXPORT'] ?? 'ON';
        $button_settings['BTN_IMPORT'] = $all_settings['BTN_IMPORT'] ?? 'ON';
        $button_settings['BTN_AGENCY_SETTING'] = $all_settings['BTN_AGENCY_SETTING'] ?? 'ON';
        $button_settings['BTN_CARD'] = $all_settings['BTN_CARD'] ?? 'ON';
        $button_settings['BTN_ADD'] = $all_settings['BTN_ADD'] ?? 'ON';
        $button_settings['BTN_ID_CARD1'] = $all_settings['BTN_ID_CARD1'] ?? 'ON'; 
        $button_settings['BTN_EDIT'] = $all_settings['BTN_EDIT'] ?? 'ON'; 
        $button_settings['BTN_DELETE'] = $all_settings['BTN_DELETE'] ?? 'ON'; 

    } catch (PDOException $e) {
        $error_message .= " | ⚠️ ข้อผิดพลาดในการดึงค่าตั้งค่าปุ่ม: " . $e->getMessage();
        $button_settings = [
            'BTN_EXPORT' => 'ON', 
            'BTN_IMPORT' => 'ON', 
            'BTN_AGENCY_SETTING' => 'ON', 
            'BTN_CARD' => 'ON', 
            'BTN_ADD' => 'ON', 
            'BTN_ID_CARD1' => 'ON',
            'BTN_EDIT' => 'ON',
            'BTN_DELETE' => 'ON'
        ];
    }
} else {
    $button_settings = [
        'BTN_EXPORT' => 'ON', 
        'BTN_IMPORT' => 'ON', 
        'BTN_AGENCY_SETTING' => 'ON', 
        'BTN_CARD' => 'ON', 
        'BTN_ADD' => 'ON', 
        'BTN_ID_CARD1' => 'ON',
        'BTN_EDIT' => 'ON',
        'BTN_DELETE' => 'ON'
    ];
}


// 2. ดึงข้อมูลหน่วยงานของผู้ใช้ปัจจุบันจาก agency_settings
$agency_info = [
    'agency_name' => '',
    'province' => '',
    'agency_logo' => ''
];
$agency_table_name = 'agency_settings';
if (isset($pdo) && $user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value 
                                FROM {$agency_table_name} 
                                WHERE user_id = :user_id 
                                AND setting_key IN ('agency_name', 'province', 'agency_logo')");
        $stmt->execute([':user_id' => $user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $agency_info['agency_name'] = $rows['agency_name'] ?? '';
        $agency_info['province'] = $rows['province'] ?? '';
        $agency_info['agency_logo'] = $rows['agency_logo'] ?? '';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Base table or view not found') === false) {
             error_log("Database error fetching agency info: " . $e->getMessage());
        }
    }
}


// 4. ดึงข้อมูลพนักงานทั้งหมด, การค้นหา, และการจำกัดจำนวน
$employees = []; 
$total_records = 0; 
$total_pages = 0;   

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit_options = [10, 20, 50, 100];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_options) ? (int)$_GET['limit'] : 10;

$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$where_parts = []; 
$params = [];

if (!empty($search)) {
    $search_condition = "(employee_name LIKE :search_name OR position_name LIKE :search_pos OR citizen_id LIKE :search_citizen)";
    $where_parts[] = $search_condition;
                      
    $search_param = '%' . $search . '%';
    $params['search_name'] = $search_param;
    $params['search_pos'] = $search_param;
    $params['search_citizen'] = $search_param;
}

if (!$is_admin && is_logged_in() && $user_id > 0) {
    $user_condition = "created_by_user_id = :user_id";
    $where_parts[] = $user_condition;
    $params['user_id'] = $user_id; 
}

$where_clause = '';
if (count($where_parts) > 0) {
    $where_clause = " WHERE " . implode(" AND ", $where_parts);
}

if (isset($pdo)) {
    try {
        $count_sql = "SELECT COUNT(*) FROM employees" . $where_clause;
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        
        $total_pages = ceil($total_records / $limit);
        if ($page > $total_pages && $total_pages > 0) {
            $page = $total_pages;
            $offset = ($page - 1) * $limit;
        } elseif ($total_pages == 0) {
            $page = 1;
            $offset = 0;
        }
        
        $sql = "SELECT id, employee_name, position_name, academic_standing, position_type, school_affiliation
                FROM employees" . $where_clause . "
                ORDER BY id DESC 
                LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            if ($key === 'user_id') {
                $stmt->bindValue(":$key", $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
            }
        }
        
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error_message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    }
} else {
    $error_message = "ไม่พบการเชื่อมต่อฐานข้อมูล กรุณาตรวจสอบไฟล์ config.php";
}

$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการข้อมูลบุคลากร</title>
    
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Noto Sans Thai', sans-serif; }
    </style>
</head>
<body class="min-h-screen w-full bg-gray-50 text-slate-800">

	<?php include 'header.php'; ?>
    
    <main class="w-full max-w-7xl mx-auto p-4 lg:p-6 space-y-6">

        <div class="bg-white rounded-xl shadow-md p-6">
            <div class="mb-6 flex flex-col xl:flex-row justify-between items-start xl:items-center gap-4 border-b border-slate-100 pb-5"> 
                
                <div>
                    <?php if (!empty($agency_info['agency_name'])): ?>
                        <div class="flex items-center gap-3">
                            <!-- แสดงโลโก้หน่วยงานถ้ามีรูปอยู่จริง -->
                            <?php if (!empty($agency_info['agency_logo']) && file_exists($agency_info['agency_logo'])): ?>
                                <img src="<?= htmlspecialchars($agency_info['agency_logo']) ?>" 
                                     alt="โลโก้หน่วยงาน" 
                                     class="w-12 h-12 object-contain rounded">
                            <?php else: ?>
                                <div class="p-2.5 bg-blue-50 text-blue-600 rounded-lg shrink-0">
                                    <i data-lucide="building-2" class="w-6 h-6"></i>
                                </div>
                            <?php endif; ?>

                            <!-- ส่วนแสดงชื่อหน่วยงานและจังหวัด (จัดเรียงแนวตั้ง) -->
                            <div class="flex flex-col">
                                <span class="text-lg font-bold text-blue-600 leading-snug">
                                    <?= htmlspecialchars($agency_info['agency_name']) ?>
                                </span>
                                <?php if (!empty($agency_info['province'])): ?>
                                    <?php 
                                        $prov_name = trim($agency_info['province']);
                                        if (mb_strpos($prov_name, 'จังหวัด') !== 0) {
                                            $prov_name = 'จังหวัด' . $prov_name;
                                        }
                                    ?>
                                    <span class="text-sm font-medium text-slate-500">
                                        <?= htmlspecialchars($prov_name) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-lg font-bold text-slate-700 flex items-center gap-2">
                            <i data-lucide="building-2" class="w-5 h-5"></i>
                            การจัดการข้อมูลบุคลากร
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex flex-wrap items-center gap-2">
                    <?php if ($button_settings['BTN_ADD'] === 'ON'): ?>
                        <a href="add.php" class="px-4 py-2 rounded-lg font-medium text-sm text-white bg-green-600 hover:bg-green-700 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="plus-circle" class="w-4 h-4"></i> เพิ่มข้อมูลใหม่
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($button_settings['BTN_AGENCY_SETTING'] === 'ON'): ?>
                        <a href="agency_settings.php" class="px-4 py-2 rounded-lg font-medium text-sm text-white bg-orange-500 hover:bg-orange-600 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="settings" class="w-4 h-4"></i> ตั้งค่าหน่วยงาน
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($button_settings['BTN_EXPORT'] === 'ON'): ?>
                        <a href="export.php" class="px-4 py-2 rounded-lg font-medium text-sm text-white bg-blue-600 hover:bg-blue-700 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="download" class="w-4 h-4"></i> ส่งออกข้อมูล
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($button_settings['BTN_IMPORT'] === 'ON'): ?>
                        <a href="import.php" class="px-4 py-2 rounded-lg font-medium text-sm text-slate-800 bg-yellow-400 hover:bg-yellow-500 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="upload" class="w-4 h-4"></i> นำเข้าข้อมูล
                        </a>
                    <?php endif; ?>

                    <?php if ($button_settings['BTN_CARD'] === 'ON'): ?>
                        <a href="card.php" class="px-4 py-2 rounded-lg font-medium text-sm text-white bg-pink-600 hover:bg-pink-700 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="credit-card" class="w-4 h-4"></i> พิมพ์บัตร
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($is_admin): ?>
                        <a href="admin_settings.php" class="px-4 py-2 rounded-lg font-medium text-sm text-white bg-slate-600 hover:bg-slate-700 flex items-center gap-2 transition shadow-sm">
                            <i data-lucide="sliders" class="w-4 h-4"></i> ตั้งค่าระบบ
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error_message): ?>
                <div class="p-4 border border-red-200 bg-red-50 text-red-700 rounded-xl mb-6 flex items-center gap-2 text-sm font-medium">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 shrink-0"></i>
                    <?= $error_message ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="p-4 border border-blue-200 bg-blue-50 text-blue-700 rounded-xl mb-6 flex items-center gap-2 text-sm font-medium">
                    <i data-lucide="check-circle-2" class="w-5 h-5 text-blue-500 shrink-0"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="mb-4">
                <form action="list.php" method="GET" class="flex flex-wrap items-end gap-3">
                    
                    <div class="flex items-center flex-1 md:flex-none"> 
                        <div class="relative w-full md:w-80">
                            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                            <input type="text" id="search" name="search" 
                                placeholder="ค้นหา ชื่อ, ตำแหน่ง, หรือ เลขบัตร ปชช"
                                value="<?= htmlspecialchars($search) ?>"
                                class="w-full pl-9 pr-3 py-2 border border-slate-300 rounded-l-lg focus:ring-2 focus:ring-blue-300 focus:outline-none text-sm transition">
                        </div>
                        <button type="submit" class="hidden">
                            ค้นหา
                        </button>
                    </div>

                    <?php if (!empty($search) || $limit != 20): ?>
                        <a href="list.php?limit=<?= $limit ?>" class="px-4 py-2 border border-slate-300 rounded-lg text-slate-600 bg-white hover:bg-slate-50 transition shadow-sm text-sm flex items-center gap-2">
                            <i data-lucide="rotate-ccw" class="w-4 h-4"></i> รีเซ็ต
                        </a>
                    <?php endif; ?>

                    <div class="ml-auto flex items-center gap-2 mt-2 md:mt-0">
                        <label for="limit" class="text-sm font-medium text-slate-600 whitespace-nowrap">แสดง:</label>
                        <select name="limit" id="limit" onchange="this.form.submit()" class="py-2 px-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-300 focus:outline-none text-sm bg-white cursor-pointer shadow-sm">
                            <?php foreach ($limit_options as $option): ?>
                                <option value="<?= $option ?>" <?= ($limit == $option) ? 'selected' : '' ?>><?= $option ?> แถว</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            
            <div class="text-sm text-slate-500 mb-4 flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4 text-blue-500"></i>
                <span>
                    <?php if (!empty($search)): ?>
                        ผลการค้นหาสำหรับ <span class="font-bold text-slate-800">"<?= htmlspecialchars($search) ?>"</span>: 
                    <?php endif; ?>
                    พบข้อมูลทั้งหมด <span class="font-bold text-blue-600"><?= number_format($total_records) ?></span> รายการ 
                    (หน้าที่ <?= number_format($page) ?> จาก <?= number_format($total_pages) ?>)
                </span>
            </div>

            <?php if (count($employees) > 0): ?>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-sm border-collapse min-w-[1000px]">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold w-16">#ID</th>
                            <th class="px-4 py-3 text-left font-semibold">ชื่อ-นามสกุล</th>
                            <th class="px-4 py-3 text-left font-semibold">ตำแหน่ง </th>
                            <th class="px-4 py-3 text-left font-semibold w-40">ประเภท</th>
                            <th class="px-4 py-3 text-left font-semibold w-48">สังกัด</th>
                            <th class="px-4 py-3 text-center font-semibold w-56">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php 
                        $start_number = $offset + 1;
                        foreach ($employees as $employee): 
                        ?>
                            <tr class="hover:bg-blue-50/40 transition">
                                <td class="px-4 py-3 text-slate-900 font-medium"><?= $start_number++ ?></td>
                                <td class="px-4 py-3 text-slate-900 font-medium">
                                    <?= htmlspecialchars($employee['employee_name']) ?>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    <?= htmlspecialchars($employee['position_name']) ?>
                                    <?php if (!empty($employee['academic_standing'])): ?>
                                        <span class="text-[11px] font-medium text-blue-600 bg-blue-50 border border-blue-100 px-1.5 py-0.5 rounded mt-1 inline-block">
                                            <?= htmlspecialchars($employee['academic_standing']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                        <?= htmlspecialchars($employee['position_type']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700 text-xs">
                                    <?= htmlspecialchars($employee['school_affiliation']) ?>
                                </td>
                               
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <a href="generate_card.php?id=<?= $employee['id'] ?>" class="text-emerald-600 hover:text-emerald-800 font-medium mr-3 text-xs hover:underline inline-flex items-center gap-1">บัตรข้าราชการ</a>
                                    
                                    <?php if ($button_settings['BTN_ID_CARD1'] === 'ON'): ?> 		
                                        <a href="generate_combined_card.php?id=<?= $employee['id'] ?>" class="text-pink-600 hover:text-pink-800 font-medium mr-3 text-xs hover:underline inline-flex items-center gap-1">บัตรควบ</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($button_settings['BTN_EDIT'] === 'ON'): ?> 
                                        <a href="edit.php?id=<?= $employee['id'] ?>" class="text-blue-600 hover:text-blue-800 font-medium mr-3 text-xs hover:underline inline-flex items-center gap-1">แก้ไข</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($button_settings['BTN_DELETE'] === 'ON'): ?> 
                                        <button type="button" 
                                                onclick="openDeleteModal('delete.php?id=<?= $employee['id'] ?>', '<?= htmlspecialchars($employee['employee_name'], ENT_QUOTES) ?>')" 
                                                class="text-rose-500 hover:text-rose-700 font-medium text-xs hover:underline inline-flex items-center gap-1 cursor-pointer">
                                            ลบ
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-6 flex flex-wrap justify-center items-center gap-2">
                <?php
                $nav_url = function($pageNum) use ($search, $limit) {
                    return "list.php?search=" . urlencode($search) . "&limit=" . $limit . "&page=" . $pageNum;
                };
                ?>

                <?php if ($page > 1): ?>
                    <a href="<?= $nav_url($page - 1) ?>" class="px-3 py-1.5 border border-slate-300 rounded-lg bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition shadow-sm">
                        &laquo; ก่อนหน้า
                    </a>
                <?php else: ?>
                    <span class="px-3 py-1.5 border border-slate-200 rounded-lg bg-slate-50 text-slate-400 cursor-not-allowed text-sm font-medium">
                        &laquo; ก่อนหน้า
                    </span>
                <?php endif; ?>

                <?php 
                $start_loop = max(1, $page - 2);
                $end_loop = min($total_pages, $page + 2);
                
                if ($start_loop > 1) {
                    echo '<a href="' . $nav_url(1) . '" class="px-3.5 py-1.5 border border-slate-300 rounded-lg bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition shadow-sm">1</a>';
                    if ($start_loop > 2) {
                        echo '<span class="px-2 py-1.5 text-slate-400">...</span>';
                    }
                }
                
                for ($i = $start_loop; $i <= $end_loop; $i++) {
                    if ($i == $page) {
                        echo '<span class="px-3.5 py-1.5 border border-blue-600 rounded-lg bg-blue-600 text-white font-bold text-sm shadow-sm">' . number_format($i) . '</span>';
                    } else {
                        echo '<a href="' . $nav_url($i) . '" class="px-3.5 py-1.5 border border-slate-300 rounded-lg bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition shadow-sm">' . number_format($i) . '</a>';
                    }
                }
                
                if ($end_loop < $total_pages) {
                    if ($end_loop < $total_pages - 1) {
                        echo '<span class="px-2 py-1.5 text-slate-400">...</span>';
                    }
                    echo '<a href="' . $nav_url($total_pages) . '" class="px-3.5 py-1.5 border border-slate-300 rounded-lg bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition shadow-sm">' . number_format($total_pages) . '</a>';
                }
                ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= $nav_url($page + 1) ?>" class="px-3 py-1.5 border border-slate-300 rounded-lg bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium transition shadow-sm">
                        ถัดไป &raquo;
                    </a>
                <?php else: ?>
                    <span class="px-3 py-1.5 border border-slate-200 rounded-lg bg-slate-50 text-slate-400 cursor-not-allowed text-sm font-medium">
                        ถัดไป &raquo;
                    </span>
                <?php endif; ?>
            </div>

            <?php else: ?>
                <div class="text-center p-8 border border-dashed border-slate-300 rounded-xl bg-slate-50">
                    <i data-lucide="inbox" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                    <p class="text-slate-500 font-medium">
                        <?php echo !empty($search) ? "ไม่พบข้อมูลบุคลากรที่ค้นหาด้วย <span class='text-slate-800 font-bold'>'{$search}'</span>" : "ยังไม่มีข้อมูลบุคลากรในระบบ"; ?>
                    </p>
                </div>
            <?php endif; ?>
            
        </div>

    </main>

    <!-- Modal ยืนยันการลบข้อมูล -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeDeleteModal(event)">
        <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border border-slate-100 text-center relative transform transition-all scale-100" onclick="event.stopPropagation()">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-rose-100 text-rose-600 mb-4">
                <i data-lucide="alert-triangle" class="w-7 h-7"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-800 mb-2">ยืนยันการลบข้อมูล</h3>
            <p class="text-sm text-slate-500 mb-6 leading-relaxed">
                คุณต้องการลบข้อมูลของ <br><span id="deleteTargetName" class="font-bold text-slate-800 break-words"></span> ใช่หรือไม่?<br>
                <span class="text-xs text-rose-500">*การดำเนินการนี้ไม่สามารถยกเลิกได้</span>
            </p>
            <div class="flex items-center justify-center gap-3">
                <button type="button" onclick="closeDeleteModal()" class="w-1/2 py-2.5 px-4 rounded-xl text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 transition">
                    ยกเลิก
                </button>
                <a id="confirmDeleteLink" href="#" class="w-1/2 py-2.5 px-4 rounded-xl text-sm font-medium text-white bg-rose-600 hover:bg-rose-700 transition shadow-sm inline-flex justify-center items-center gap-1.5">
                    <i data-lucide="trash-2" class="w-4 h-4"></i> ลบข้อมูล
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            const searchInput = document.getElementById('search');
            let typingTimer;

            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(function() {
                        searchInput.form.submit();
                    }, 500); // ดีเลย์ 0.5 วินาทีหลังหยุดพิมพ์
                });

                searchInput.addEventListener('keydown', function() {
                    clearTimeout(typingTimer);
                });
            }
        });

        // ฟังก์ชันควบคุม Delete Modal
        function openDeleteModal(deleteUrl, employeeName) {
            const modal = document.getElementById('deleteModal');
            const targetName = document.getElementById('deleteTargetName');
            const confirmLink = document.getElementById('confirmDeleteLink');

            if (modal && targetName && confirmLink) {
                targetName.textContent = employeeName;
                confirmLink.href = deleteUrl;
                modal.classList.remove('hidden');
            }
        }

        function closeDeleteModal(event) {
            const modal = document.getElementById('deleteModal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // ปิด Modal เมื่อกดปุ่ม ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteModal();
            }
        });
    </script>
	<script>
		document.addEventListener('DOMContentLoaded', function() {
            // ตรวจสอบว่ามี query parameter บน URL หรือไม่
            if (window.history.replaceState && window.location.search.includes('message=')) {
                // สร้าง URL ใหม่ที่ไม่มี query string
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                // เคลียร์ URL บน browser โดยไม่ทำให้หน้าเว็บโหลดใหม่
                window.history.replaceState({path: cleanUrl}, '', cleanUrl);
            }
        });
    </script>
</body>
</html>