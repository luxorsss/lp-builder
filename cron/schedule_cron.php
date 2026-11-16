<?php
    // cron/schedule_cron.php
    require_once __DIR__ . '/../includes/config.php';
  
    try {
        $current_time = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            UPDATE landing_pages 
            SET status = next_scheduled_status, 
                next_scheduled_status = NULL, 
                next_schedule_time = NULL 
            WHERE next_schedule_time IS NOT NULL 
              AND next_schedule_time <= ? 
              AND next_scheduled_status IS NOT NULL 
              AND next_scheduled_status IN ('published', 'draft')
        ");
        $stmt->execute([$current_time]);
        $affected = $stmt->rowCount();
        echo "Cron selesai: " . date('Y-m-d H:i:s') . " | Updated: $affected pages\n";
    } catch (Exception $e) {
        echo "Cron error: " . $e->getMessage() . "\n";
        exit(1);
    }