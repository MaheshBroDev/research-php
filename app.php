<?php
header('Content-Type: application/json');

// Check if the autoloader exists before requiring it
$autoloaderPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloaderPath)) {
    require $autoloaderPath;
} else {
    http_response_code(500);
    echo json_encode(["error" => "Autoloader not found. Please run composer install."]);
    exit;
}


$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'research_php'
);
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
// $password = "";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed","error_message" => $e->getMessage()]);
    exit;
}

function logPerformanceMetrics($endpoint, $startTime) {
    $elapsedTime = round((microtime(true) - $startTime) * 1000, 2);
    $memoryUsage = memory_get_usage();
    // Windows-compatible CPU usage measurement
    $cpuUsage = 0;
    if (function_exists('sys_getloadavg')) {
        $cpuUsage = sys_getloadavg()[0] * 100;
    } else {
        // Windows alternative (less accurate)
        $cmd = "wmic cpu get loadpercentage";
        @exec($cmd, $output);
        if (isset($output[1])) {
            $cpuUsage = (int)$output[1];
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    
    $jsonFile = 'performance_metrics_php.json';
    $data = [
        'timestamp' => $timestamp,
        'endpoint' => $endpoint,
        'rss' => $memoryUsage,
        'heapTotal' => memory_get_usage(true),
        'heapUsed' => $memoryUsage,
        'elapsedTime' => $elapsedTime,
        'cpuUsage' => $cpuUsage,
        'memoryUsage' => round($memoryUsage / 1024 / 1024, 2) . ' MB'
    ];
    
    $existingData = [];
    if (file_exists($jsonFile)) {
        $existingData = json_decode(file_get_contents($jsonFile), true);
    }
    $existingData[] = $data;
    file_put_contents($jsonFile, json_encode($existingData, JSON_PRETTY_PRINT));
}

// Function to log Docker stats
function logDockerStats($endpoint) {
    $timestamp = date('Y-m-d H:i:s');
    $dockerStatsFile = 'docker_metrics_php.json';

    // Get CPU usage
    $cpuUsage = 0;
    $cmdCpu = "top -bn1 | grep Cpu | awk '{printf \"%.1f\", $2+$4}'";
    @exec($cmdCpu, $outputCpu);
    if (isset($outputCpu[0])) {
        $cpuUsage = (float)$outputCpu[0];
    }

    // Get memory usage
    $memoryUsage = 0;
    $cmdMem = "free | grep Mem | awk '{print $3/$2 * 100.0}'";
    @exec($cmdMem, $outputMem);
    if (isset($outputMem[0])) {
        $memoryUsage = (float)$outputMem[0];
    }

    $data = [
        'timestamp' => $timestamp,
        'endpoint' => $endpoint,
        'cpuUsage' => $cpuUsage,
        'memoryUsage' => $memoryUsage
    ];

    $existingData = [];
    if (file_exists($dockerStatsFile)) {
        $existingData = json_decode(file_get_contents($dockerStatsFile), true);
    }
    $existingData[] = $data;
    file_put_contents($dockerStatsFile, json_encode($existingData, JSON_PRETTY_PRINT));
}

function authMiddleware() {
    global $pdo;
    $headers = getallheaders();
    if (!isset($headers['Authorization']) || !preg_match('/Bearer (.+)/', $headers['Authorization'], $matches)) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized"]);
        exit;
    }
    $token = $matches[1];
    $stmt = $pdo->prepare("SELECT username FROM users WHERE token = ?");
    $stmt->execute([$token]);
    if ($stmt->rowCount() === 0) {
        http_response_code(401);
        echo json_encode(["error" => "Unauthorized"]);
        exit;
    }
    return $stmt->fetch(PDO::FETCH_ASSOC)['username'];
}

// Sorting functions
function bubbleSort(array $arr): array {
    $size = count($arr);
    for ($i = 0; $i < $size - 1; $i++) {
        for ($j = 0; $j < $size - $i - 1; $j++) {
            if ($arr[$j] > $arr[$j + 1]) {
                // Swap elements
                $temp = $arr[$j];
                $arr[$j] = $arr[$j + 1];
                $arr[$j + 1] = $temp;
            }
        }
    }
    return $arr;
}

function quickSort(array $arr): array {
    if (count($arr) < 2) {
        return $arr;
    }

    $pivot = $arr[0];
    $left = $right = [];

    for ($i = 1; $i < count($arr); $i++) {
        if ($arr[$i] < $pivot) {
            $left[] = $arr[$i];
        } else {
            $right[] = $arr[$i];
        }
    }

    return array_merge(quickSort($left), [$pivot], quickSort($right));
}

function binaryInsertionSort(array $arr): array {
    $count = count($arr);
    for ($i = 1; $i < $count; $i++) {
        $key = $arr[$i];
        $left = 0;
        $right = $i - 1;

        // Binary search to find the correct position
        while ($left <= $right) {
            $mid = floor(($left + $right) / 2);
            if ($key < $arr[$mid]) {
                $right = $mid - 1;
            } else {
                $left = $mid + 1;
            }
        }

        // Shift elements to make space
        for ($j = $i - 1; $j >= $left; $j--) {
            $arr[$j + 1] = $arr[$j];
        }

        $arr[$left] = $key;
    }
    return $arr;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$startTime = microtime(true);

switch ($path) {
    case '/login':
        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("SELECT token FROM users WHERE username = ? AND password = ?");
            $stmt->execute([$input['username'], $input['password']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                echo json_encode(["token" => $result['token']]);
            } else {
                http_response_code(401);
                echo json_encode(["error" => "Invalid credentials"]);
            }
        }
        break;
    case '/items':
        if ($method === 'GET') {
            authMiddleware();
            $stmt = $pdo->query("SELECT id, name, value FROM items");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        break;
    case '/item':
        if ($method === 'GET') {
            authMiddleware();
            if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
                http_response_code(400);
                echo json_encode(["error" => "Invalid ID"]);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id, name, value FROM items WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($result ?: ["error" => "Item not found"]);
        }
        break;
    case '/item/last':
        if ($method === 'GET') {
            authMiddleware();
            $stmt = $pdo->query("SELECT id, name, value FROM items ORDER BY id DESC LIMIT 1");
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: ["error" => "Item not found"]);
        }
        break;
    case '/items/create':
        if ($method === 'POST') {
            authMiddleware();
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO items (name, value) VALUES (?, ?)");
            if ($stmt->execute([$input['name'], $input['value']])) {
                http_response_code(201);
                echo json_encode(["message" => "Item created"]);
            } else {
                http_response_code(500);
                echo json_encode(["error" => "Error saving item"]);
            }
        }
        break;
    case '/metrics':
        if ($method === 'GET') {
            if (!file_exists('performance_metrics_php.json')) {
                http_response_code(404);
                echo json_encode(["error" => "No performance metrics available"]);
            } else {
                header('Content-Type: text/csv');
                readfile('performance_metrics_php.json');
            }
        }
        break;
    case '/docker_metrics':
        if ($method === 'GET') {
            if (!file_exists('docker_metrics_php.json')) {
                http_response_code(404);
                echo json_encode(["error" => "No docker metrics available"]);
            } else {
                header('Content-Type: application/json');
                echo file_get_contents('docker_metrics_php.json');
            }
        }
        break;
    case '/sort':
        if ($method === 'POST') {
            authMiddleware();
            $input = json_decode(file_get_contents('php://input'), true);
            $list = $input['list'];

            // Bubble Sort
            $startTimeBubble = microtime(true);
            $memoryUsageBeforeBubble = memory_get_usage();
            $cpuUsageBeforeBubble = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;
            $sortedBubble = bubbleSort($list);
            $endTimeBubble = microtime(true);
            $memoryUsageAfterBubble = memory_get_usage();
            $cpuUsageAfterBubble = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;

            // Quick Sort
            $startTimeQuick = microtime(true);
            $memoryUsageBeforeQuick = memory_get_usage();
            $cpuUsageBeforeQuick = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;
            $sortedQuick = quickSort($list);
            $endTimeQuick = microtime(true);
            $memoryUsageAfterQuick = memory_get_usage();
            $cpuUsageAfterQuick = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;

             // Binary Insertion Sort
             $startTimeBinary = microtime(true);
             $memoryUsageBeforeBinary = memory_get_usage();
             $cpuUsageBeforeBinary = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;
             $sortedBinary = binaryInsertionSort($list);
             $endTimeBinary = microtime(true);
             $memoryUsageAfterBinary = memory_get_usage();
             $cpuUsageAfterBinary = function_exists('sys_getloadavg') ? sys_getloadavg()[0] * 100 : 0;

            $results = [
                'bubbleSort' => [
                    'sortedList' => $sortedBubble,
                    'elapsedTime' => round(($endTimeBubble - $startTimeBubble) * 1000, 2) . ' ms',
                    'memoryUsage' => round(($memoryUsageAfterBubble - $memoryUsageBeforeBubble) / 1024 / 1024, 2) . ' MB',
                    'cpuUsage' => round($cpuUsageAfterBubble - $cpuUsageBeforeBubble, 2) . '%'
                ],
                'quickSort' => [
                    'sortedList' => $sortedQuick,
                    'elapsedTime' => round(($endTimeQuick - $startTimeQuick) * 1000, 2) . ' ms',
                    'memoryUsage' => round(($memoryUsageAfterQuick - $memoryUsageBeforeQuick) / 1024 / 1024, 2) . ' MB',
                    'cpuUsage' => round($cpuUsageAfterQuick - $cpuUsageBeforeQuick, 2) . '%'
                ],
                'binarySort' => [
                    'sortedList' => $sortedBinary,
                    'elapsedTime' => round(($endTimeBinary - $startTimeBinary) * 1000, 2) . ' ms',
                    'memoryUsage' => round(($memoryUsageAfterBinary - $memoryUsageBeforeBinary) / 1024 / 1024, 2) . ' MB',
                    'cpuUsage' => round($cpuUsageAfterBinary - $cpuUsageBeforeBinary, 2) . '%'
                ]
            ];

            echo json_encode($results);
        }
        break;
    case preg_match('/\/loaderio-([a-zA-Z0-9]{32})\.txt/', $path, $matches) ? true : false:
        header('Content-Type: text/plain');
        echo 'loaderio-' . $matches[1];
        break;
    default:
        http_response_code(404);
        echo json_encode(["error" => "Not Found"]);
}

logPerformanceMetrics($path, $startTime);
logDockerStats($path);
