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
$password = "";
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
    
    $csvFile = 'performance_metrics_php.csv';
    $data = "$timestamp,$endpoint,$memoryUsage,$elapsedTime,$cpuUsage\n";
    file_put_contents($csvFile, $data, FILE_APPEND);
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
            if (!file_exists('performance_metrics_php.csv')) {
                http_response_code(404);
                echo json_encode(["error" => "No performance metrics available"]);
            } else {
                header('Content-Type: text/csv');
                readfile('performance_metrics_php.csv');
            }
        }
        break;
    default:
        http_response_code(404);
        echo json_encode(["error" => "Not Found"]);
}

logPerformanceMetrics($path, $startTime);
