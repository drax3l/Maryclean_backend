<?php
define('FCPATH', __DIR__ . '/public' . DIRECTORY_SEPARATOR);
require 'public/index.php';
$db = \Config\Database::connect();
try {
    $db->query("ALTER TABLE Empleado ADD COLUMN username VARCHAR(50) NULL UNIQUE AFTER nombres");
    $db->query("ALTER TABLE Empleado ADD COLUMN password VARCHAR(255) NULL AFTER username");
    $db->query("ALTER TABLE Empleado ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER password");
    echo "Done";
} catch (\Exception $e) {
    echo $e->getMessage();
}
