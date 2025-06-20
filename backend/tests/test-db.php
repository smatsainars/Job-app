<?php
// vendor/autoload.php = Composer's file that loads all packages
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;                    // For loading .env files
use Doctrine\DBAL\DriverManager;     // For creating database connections

$dotenv = Dotenv::createImmutable(__DIR__ . '/../'); // createImmutable() = create a Dotenv instance that can't be changed
$dotenv->load();  // Actually read the .env file and load variables

$connectionParams = [
    'driver' => 'pdo_mysql',           // Use MySQL with PDO driver
    'host' => $_ENV['DB_HOST'],        // Server address (usually localhost)
    'dbname' => $_ENV['DB_NAME'],      // Database name to connect to
    'user' => $_ENV['DB_USER'],        // Username for database
    'password' => $_ENV['DB_PASS'],    // Password for database user
    'port' => $_ENV['DB_PORT'],        // Port number (usually 3306 for MySQL)
];

// Line 21: Start error handling block
// try = "attempt this code, catch errors if they happen"
try {
    $connection = DriverManager::getConnection($connectionParams);
    
    // Line 26-27: Test the connection by running a simple query
    // executeQuery() = run a SQL query and return results
    // 'SELECT 1' = simplest possible query, just returns the number 1
    $result = $connection->executeQuery('SELECT 1');
    $result->fetchOne(); // Actually execute and get the result (triggers connection)
    
    // Line 29-32: Display success messages
    echo "✅ Database connection: SUCCESS\n";      // \n = new line
    echo "🔧 Connected to: " . $_ENV['DB_HOST'] . ":" . $_ENV['DB_PORT'] . "\n";
    echo "🗄️  Database: " . $_ENV['DB_NAME'] . "\n";
    echo "👤 User: " . $_ENV['DB_USER'] . "\n\n";  // \n\n = two new lines
    
    // Line 35-37: Get MySQL version information
    // SELECT VERSION() = MySQL function that returns server version
    $result = $connection->executeQuery('SELECT VERSION() as version');
    $version = $result->fetchAssociative();  // Get result as associative array
    echo "📊 MySQL version: " . $version['version'] . "\n";
    
    // Line 40-42: List all tables in the database
    // SHOW TABLES = MySQL command to list all tables
    $tablesResult = $connection->executeQuery('SHOW TABLES');
    $tables = $tablesResult->fetchAllAssociative();  // Get all results as array
    echo "📋 Tables found: " . count($tables) . "\n";  // count() = number of items
    
    // Line 44-51: Show sample table names if any exist
    if (count($tables) > 0) {  // if there are tables...
        echo "📝 Sample tables:\n";
        $sampleTables = array_slice($tables, 0, 3); // Get first 3 tables only
        foreach ($sampleTables as $table) {  // Loop through each table
            echo "   - " . array_values($table)[0] . "\n";  // Print table name
        }
    }
    
    // Line 53: Final success message
    echo "\n🎉 All tests passed! Database is ready to use.\n";
    
// Line 55: Catch any errors that happened in the try block
} catch (\Exception $e) {
    // Line 56-58: Display error information
    echo "❌ Connection failed!\n";
    echo "🚨 Error: " . $e->getMessage() . "\n";  // Get error message
    echo "\n🔍 Check your .env file settings:\n";
    
    // Line 59-62: Show current environment variable values for debugging
    // ?? 'NOT SET' = if variable doesn't exist, show 'NOT SET' instead
    echo "   DB_HOST=" . ($_ENV['DB_HOST'] ?? 'NOT SET') . "\n";
    echo "   DB_NAME=" . ($_ENV['DB_NAME'] ?? 'NOT SET') . "\n";
    echo "   DB_USER=" . ($_ENV['DB_USER'] ?? 'NOT SET') . "\n";
    echo "   DB_PORT=" . ($_ENV['DB_PORT'] ?? 'NOT SET') . "\n";
}
// Line 64: PHP closing tag
?>