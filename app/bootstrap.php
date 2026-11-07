<?php
/**
 * ค่าตั้งต้นที่ทุกหน้าใช้ร่วมกัน: เวลาไทย + ไม่แสดง error ภายในให้ผู้ใช้เห็น (เขียนลง error log แทน)
 */
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
