<?php
/**
 * ปฏิทินจันทรคติไทย (แบบปฏิทินหลวง) — แปลงวันสุริยคติ → ขึ้น/แรม ค่ำ เดือน ปีนักษัตร
 *
 * หลักการ: นับวันต่อเนื่องจากวันอ้างอิง (epoch) ที่รู้ว่าเป็น "ขึ้น 1 ค่ำ เดือนอ้าย (เดือน 1)"
 * แล้วไล่ความยาวเดือนจันทรคติไปเรื่อยๆ
 *   - เดือนคี่ 29 วัน (ขึ้น 1-15, แรม 1-14)
 *   - เดือนคู่ 30 วัน (ขึ้น 1-15, แรม 1-15)
 *   - ปีอธิกมาส: มีเดือน 8 สองหน (8 แรก / 8 หลัง) เพิ่ม 30 วัน → ปีละ 384 วัน
 *   - ปีอธิกวาร: เดือน 7 มี 30 วันแทน 29 → ปีละ 355 วัน
 *   - ปีปกติ 354 วัน
 *
 * ความแม่นยำ: ตรวจสอบแล้วกับปฏิทินวันพระจริงทั้งปี พ.ศ. 2569
 * ด้วย tools/test_thai_lunar.php — ถ้าแก้ค่าคงที่ใดๆ ต้องรันเทสต์ซ้ำ
 *
 * ข้อจำกัด: ตาราง "ปีอธิกวาร" ครอบคลุม พ.ศ. 2500–2595 เท่านั้น
 * นอกช่วงนี้ผลลัพธ์อาจคลาดเคลื่อน — ฟังก์ชันจะคืน null
 */

/** วันอ้างอิง: ขึ้น 1 ค่ำ เดือน 1 */
const TL_EPOCH = '1956-12-03';
/** ปี ค.ศ. ที่ใช้ตรวจอธิกมาส/อธิกวาร ของปีจันทรคติที่เริ่มต้นที่ epoch */
const TL_EPOCH_YEAR = 1957;

/** ปีอธิกวาร (พ.ศ.) — เดือน 7 มี 30 วัน */
const TL_ATIKAWAN_BE = [
    2500, 2506, 2513, 2516, 2522, 2530, 2533, 2540, 2543, 2549,
    2552, 2559, 2563, 2568, 2575, 2578, 2586, 2589, 2595,
];

const TL_MONTH_NAMES = [
    1 => 'อ้าย', 2 => 'ยี่', 3 => 'สาม', 4 => 'สี่', 5 => 'ห้า', 6 => 'หก',
    7 => 'เจ็ด', 8 => 'แปด', 9 => 'เก้า', 10 => 'สิบ', 11 => 'สิบเอ็ด', 12 => 'สิบสอง',
];

const TL_ZODIAC = ['ชวด', 'ฉลู', 'ขาล', 'เถาะ', 'มะโรง', 'มะเส็ง', 'มะเมีย', 'มะแม', 'วอก', 'ระกา', 'จอ', 'กุน'];

/** ปีนั้นเป็นอธิกมาส (มีเดือน 8 สองหน) หรือไม่ — สูตรของ ลอย ชุนพงษ์ทอง (มหาศักราช = ค.ศ. - 78) */
function tl_is_atikamas(int $yearCE): bool
{
    return fmod(($yearCE - 78) - 0.45222, 2.7118886) < 1;
}

/** ปีนั้นเป็นอธิกวาร (เดือน 7 มี 30 วัน) หรือไม่ — อธิกมาสมาก่อนเสมอ ปีเดียวกันเป็นทั้งสองไม่ได้ */
function tl_is_atikawan(int $yearCE): bool
{
    return !tl_is_atikamas($yearCE) && in_array($yearCE + 543, TL_ATIKAWAN_BE, true);
}

/**
 * ลำดับเดือนของปีจันทรคติหนึ่งปี พร้อมความยาวแต่ละเดือน
 * @return array<int, array{m:int, len:int, second8:bool}>
 */
function tl_months_of_year(int $yearCE): array
{
    $atikamas = tl_is_atikamas($yearCE);
    $atikawan = tl_is_atikawan($yearCE);
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $len = ($m % 2 === 1) ? 29 : 30;
        if ($m === 7 && $atikawan) {
            $len = 30;
        }
        $out[] = ['m' => $m, 'len' => $len, 'second8' => false];
        if ($m === 8 && $atikamas) {
            $out[] = ['m' => 8, 'len' => 30, 'second8' => true]; // เดือน 8 หลัง
        }
    }
    return $out;
}

/**
 * แปลงวันสุริยคติ (YYYY-MM-DD) → ข้อมูลจันทรคติ (รวมปีนักษัตร)
 * @return array{month:int, month_name:string, second8:bool, phase:string, day:int,
 *               is_holy:bool, zodiac:string, atikamas:bool, atikawan:bool, label:string}|null
 *         null = อยู่นอกช่วงที่คำนวณได้อย่างมั่นใจ
 */
function tl_lunar_date(string $date): ?array
{
    $core = tl_lunar_core($date);
    if ($core === null) {
        return null;
    }
    $core['zodiac'] = tl_zodiac_of($date);
    return $core;
}

/**
 * แกนคำนวณจันทรคติ (ไม่รวมปีนักษัตร) — แยกออกมาเพราะการหาปีนักษัตร
 * ต้องเรียกฟังก์ชันนี้ซ้ำ ถ้ารวมไว้ด้วยกันจะเรียกวนไม่รู้จบ
 */
function tl_lunar_core(string $date): ?array
{
    $epoch = new DateTimeImmutable(TL_EPOCH);
    $target = new DateTimeImmutable($date);
    $days = (int) $epoch->diff($target)->format('%r%a');
    if ($days < 0) {
        return null; // ก่อน epoch — ไม่รองรับ
    }

    $year = TL_EPOCH_YEAR;
    $months = [];
    while (true) {
        if ($year + 543 > 2595) {
            return null; // เกินตารางอธิกวารที่มี
        }
        $months = tl_months_of_year($year);
        $yearLen = 0;
        foreach ($months as $mo) {
            $yearLen += $mo['len'];
        }
        if ($days < $yearLen) {
            break;
        }
        $days -= $yearLen;
        $year++;
    }

    foreach ($months as $mo) {
        if ($days < $mo['len']) {
            $dayInMonth = $days + 1;              // 1..len
            $waxing = $dayInMonth <= 15;
            $phaseDay = $waxing ? $dayInMonth : $dayInMonth - 15;
            // วันพระ = ขึ้น 8, ขึ้น 15, แรม 8, และวันสุดท้ายของเดือน (แรม 14 หรือ 15)
            $lastWaning = $mo['len'] - 15;
            $isHoly = ($phaseDay === 8) || ($waxing && $phaseDay === 15) || (!$waxing && $phaseDay === $lastWaning);
            $name = TL_MONTH_NAMES[$mo['m']];
            if ($mo['m'] === 8 && tl_is_atikamas($year)) {
                $name .= $mo['second8'] ? ' (หลัง)' : ' (แรก)';
            }
            return [
                'month'      => $mo['m'],
                'month_name' => $name,
                'second8'    => $mo['second8'],
                'phase'      => $waxing ? 'ขึ้น' : 'แรม',
                'day'        => $phaseDay,
                'is_holy'    => $isHoly,
                'atikamas'   => tl_is_atikamas($year),
                'atikawan'   => tl_is_atikawan($year),
                'label'      => ($waxing ? 'ขึ้น' : 'แรม') . ' ' . $phaseDay . ' ค่ำ เดือน' . $name,
            ];
        }
        $days -= $mo['len'];
    }
    return null;
}

/* ============================================================
 * กาลโยค — วันธงชัย / วันอธิบดี / วันอุบาทว์ / วันโลกาวินาศ
 *
 * คำนวณจากจุลศักราช (จ.ศ.) ตามตำราโหราศาสตร์ไทย โดยกาลโยค
 * "เปลี่ยนปี" ทุกวันที่ 16 เมษายน (ไม่ใช่ 1 มกราคม)
 *   วันธงชัย     = (จ.ศ. × 10 + 3) mod 7
 *   วันอธิบดี    = (จ.ศ. mod 498) mod 7
 *   วันอุบาทว์   = (จ.ศ. × 10 + 2) mod 7
 *   วันโลกาวินาศ = (จ.ศ. + 1120) mod 7
 * เศษ 1–7 = อาทิตย์–เสาร์ (เศษ 0 นับเป็น 7 = เสาร์)
 *
 * ตรวจสอบแล้วกับปฏิทินฤกษ์ที่เผยแพร่จริงของ พ.ศ. 2568 และ 2569
 * ทั้งช่วงก่อนและหลัง 16 เมษายน (ดู tools/test_thai_lunar.php)
 *
 * หมายเหตุ: วันเดียวกันเป็นได้ทั้งวันดีและวันร้าย (เช่น ธงชัยกับโลกาวินาศ
 * ตรงกันในปี 2569) ซึ่งเป็นเรื่องปกติของตำรา ไม่ใช่ข้อผิดพลาด
 * ============================================================ */

/** จุลศักราชที่ใช้คำนวณกาลโยคของวันนั้น (เปลี่ยนปีวันที่ 16 เมษายน) */
function tl_chulasakarat(string $date): int
{
    $t = new DateTimeImmutable($date);
    $be = (int) $t->format('Y') + 543;
    $afterNewYear = $t >= new DateTimeImmutable($t->format('Y') . '-04-16');
    return $be - ($afterNewYear ? 1181 : 1182);
}

/** แปลงเศษ 1–7 (อาทิตย์–เสาร์) เป็นเลขวันแบบ PHP date('w') คือ 0=อาทิตย์ */
function tl_remainder_to_dow(int $r): int
{
    $r = $r % 7;
    return ($r === 0) ? 6 : $r - 1;
}

/**
 * กาลโยคของวันที่กำหนด
 * @return array{cs:int, thongchai:int, athibodi:int, ubat:int, lokawinat:int, types:string[]}
 *         ค่าวันเป็นเลขแบบ date('w') (0=อาทิตย์), types = ประเภทที่วันนั้นเข้าข่าย
 */
function tl_kalayok(string $date): array
{
    $cs = tl_chulasakarat($date);
    $thongchai  = tl_remainder_to_dow(($cs * 10 + 3) % 7);
    $athibodi   = tl_remainder_to_dow(($cs % 498) % 7);
    $ubat       = tl_remainder_to_dow(($cs * 10 + 2) % 7);
    $lokawinat  = tl_remainder_to_dow(($cs + 1120) % 7);

    $dow = (int) (new DateTimeImmutable($date))->format('w');
    $types = [];
    if ($dow === $thongchai)  { $types[] = 'ธงชัย'; }
    if ($dow === $athibodi)   { $types[] = 'อธิบดี'; }
    if ($dow === $ubat)       { $types[] = 'อุบาทว์'; }
    if ($dow === $lokawinat)  { $types[] = 'โลกาวินาศ'; }

    return [
        'cs' => $cs,
        'thongchai' => $thongchai, 'athibodi' => $athibodi,
        'ubat' => $ubat, 'lokawinat' => $lokawinat,
        'types' => $types,
    ];
}

/** วันนั้นเป็นวันมงคลตามกาลโยคหรือไม่ (ธงชัย/อธิบดี และไม่ติดวันร้าย) */
function tl_is_auspicious(string $date): bool
{
    $k = tl_kalayok($date);
    $good = in_array('ธงชัย', $k['types'], true) || in_array('อธิบดี', $k['types'], true);
    $bad  = in_array('อุบาทว์', $k['types'], true) || in_array('โลกาวินาศ', $k['types'], true);
    return $good && !$bad;
}

/**
 * ปีนักษัตรของวันนั้น — เปลี่ยนปีที่ "เดือน 5 ขึ้น 1 ค่ำ" ตามธรรมเนียมโหร
 * (ไม่ใช่ 1 มกราคม และไม่ใช่สงกรานต์)
 */
function tl_zodiac_of(string $date): string
{
    $target = new DateTimeImmutable($date);
    $ce = (int) $target->format('Y');
    // หาว่าวันนี้อยู่ก่อนหรือหลัง "เดือน 5 ขึ้น 1 ค่ำ" ของปี ค.ศ. นั้น
    $start = tl_month5_start($ce);
    $yearForZodiac = ($start !== null && $target < $start) ? $ce - 1 : $ce;
    // พ.ศ. 2564 = ปีฉลู (index 1) → ใช้เป็นจุดตั้งต้น
    $idx = (($yearForZodiac + 543) - 2564 + 1) % 12;
    $idx = ($idx % 12 + 12) % 12;
    return TL_ZODIAC[$idx];
}

/** วันที่ของ "เดือน 5 ขึ้น 1 ค่ำ" ในปี ค.ศ. ที่กำหนด (ใช้หาเส้นแบ่งปีนักษัตร) */
function tl_month5_start(int $yearCE): ?DateTimeImmutable
{
    // เดือน 5 ขึ้น 1 ค่ำ ตกราวมีนาคม–เมษายน — ไล่หาในช่วงนั้น
    $d = new DateTimeImmutable($yearCE . '-02-20');
    $end = new DateTimeImmutable($yearCE . '-05-31');
    while ($d <= $end) {
        $l = tl_lunar_core($d->format('Y-m-d'));
        if ($l !== null && $l['month'] === 5 && $l['phase'] === 'ขึ้น' && $l['day'] === 1) {
            return $d;
        }
        $d = $d->modify('+1 day');
    }
    return null;
}
