<?php
namespace App\Controllers;
class TempDb extends BaseController {
    public function index() {
        $db = \Config\Database::connect();
        try {
            $db->query("ALTER TABLE `Empleado` ADD COLUMN `username` VARCHAR(50) NULL UNIQUE AFTER `nombres`");
            $db->query("ALTER TABLE `Empleado` ADD COLUMN `password` VARCHAR(255) NULL AFTER `username`");
            $db->query("ALTER TABLE `Empleado` ADD COLUMN `activo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `password`");
            $db->query("ALTER TABLE `Empleado` ADD INDEX `idx_empleado_username` (`username`)");
            echo "ALTER TABLE SUCCESS.";
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }
}
