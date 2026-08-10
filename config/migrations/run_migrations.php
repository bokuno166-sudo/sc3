<?php
require_once __DIR__ . '/../config.php';

$migrations = glob(__DIR__ . '/*.sql');
sort($migrations);

$conn = getDBConnection();

foreach ($migrations as $file) {
    if (!file_exists($file)) {
        echo "Migration file not found: $file\n";
        continue;
    }

    $sql = file_get_contents($file);
    if (!$sql) {
        echo "Empty migration file: $file\n";
        continue;
    }

    try {
        if ($conn->multi_query($sql)) {
            // drain multi_query results
            do {
                if ($res = $conn->store_result()) {
                    $res->free();
                }
            } while ($conn->more_results() && $conn->next_result());
            echo "Applied migration: $file\n";
        } else {
            echo "Failed to apply migration: $file — " . $conn->error . "\n";
        }
    } catch (Exception $e) {
        echo "Error applying migration $file: " . $e->getMessage() . "\n";
    }
}

$conn->close();

echo "Migrations complete.\n";

// Usage (CLI): php config/migrations/run_migrations.php

?>
