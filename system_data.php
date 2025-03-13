<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root;
$dbname = 'cloudbox;
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$userId = $_SESSION['user_id'];
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

// Data collection
$data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'system' => [],
    'personal' => []
];

// System information (only available to admins)
if ($isAdmin) {
    // CPU Usage
    $load = sys_getloadavg();
    $cpuCores = shell_exec('nproc');
    $data['system']['cpu'] = [
        'load_1min' => round($load[0] * 100 / $cpuCores, 2),
        'load_5min' => round($load[1] * 100 / $cpuCores, 2),
        'load_15min' => round($load[2] * 100 / $cpuCores, 2)
    ];
    
    // Memory Usage
    $meminfo = shell_exec('free -m');
    preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)/m', $meminfo, $matches);
    $totalMem = $matches[1];
    $usedMem = $matches[2];
    $data['system']['memory'] = [
        'total' => $totalMem,
        'used' => $usedMem,
        'percent' => round(($usedMem / $totalMem) * 100, 2)
    ];
    
    // Disk Usage
    $disk = shell_exec('df -h / | tail -1');
    preg_match('/(\d+)%/', $disk, $matches);
    $diskPercent = $matches[1];
    $data['system']['disk'] = [
        'percent' => $diskPercent
    ];
    
    // CPU Temperature (Raspberry Pi specific)
    if (file_exists('/sys/class/thermal/thermal_zone0/temp')) {
        $temp = intval(file_get_contents('/sys/class/thermal/thermal_zone0/temp'));
        $tempCelsius = round($temp / 1000, 1);
        $data['system']['temperature'] = $tempCelsius;
    } else {
        $data['system']['temperature'] = null; // Not available
    }
    
    // Global storage usage
    $totalStorageQuery = "SELECT SUM(file_size) as total_size FROM files";
    $result = $conn->query($totalStorageQuery);
    $row = $result->fetch_assoc();
    $totalStorageUsed = $row['total_size'] ?: 0;
    $data['system']['storage'] = [
        'used' => round($totalStorageUsed / (1024 * 1024), 2) // MB
    ];
    
    // User count
    $userCountQuery = "SELECT COUNT(*) as count FROM users";
    $result = $conn->query($userCountQuery);
    $row = $result->fetch_assoc();
    $data['system']['users'] = $row['count'];
    
    // File count
    $fileCountQuery = "SELECT COUNT(*) as count FROM files";
    $result = $conn->query($fileCountQuery);
    $row = $result->fetch_assoc();
    $data['system']['files'] = $row['count'];
}

// Personal storage usage (available to all users)
$userStorageQuery = $conn->prepare("SELECT SUM(file_size) as total_size FROM files WHERE user_id = ?");
$userStorageQuery->bind_param("i", $userId);
$userStorageQuery->execute();
$result = $userStorageQuery->get_result();
$row = $result->fetch_assoc();
$userStorageUsed = $row['total_size'] ?: 0;

// Get user's quota
$quotaQuery = $conn->prepare("SELECT storage_quota FROM users WHERE id = ?");
$quotaQuery->bind_param("i", $userId);
$quotaQuery->execute();
$result = $quotaQuery->get_result();
$row = $result->fetch_assoc();
$userQuota = $row['storage_quota'] ?: 104857600; // 100MB default

$data['personal']['storage'] = [
    'used' => round($userStorageUsed / (1024 * 1024), 2), // MB
    'quota' => round($userQuota / (1024 * 1024), 2), // MB
    'percent' => round(($userStorageUsed / $userQuota) * 100, 2)
];

// Return data as JSON
header('Content-Type: application/json');
echo json_encode($data);
?>
