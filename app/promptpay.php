<?php
/**
 * สร้างข้อความ QR PromptPay ตามมาตรฐาน EMVCo (สแกนด้วยแอปธนาคารไทยได้)
 */

function emvField(string $id, string $value): string
{
    return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

/** CRC-16/CCITT-FALSE ตามที่มาตรฐาน EMVCo กำหนด */
function emvCrc16(string $data): string
{
    $crc = 0xFFFF;
    foreach (str_split($data) as $ch) {
        $crc ^= ord($ch) << 8;
        for ($i = 0; $i < 8; $i++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * @param string $id เบอร์มือถือ 10 หลัก, เลขบัตร/นิติบุคคล 13 หลัก หรือ e-Wallet 15 หลัก
 * @param float|null $amount ยอดเงิน (null = ให้ผู้โอนกรอกเอง)
 * @return string|null null เมื่อรูปแบบเลขไม่ถูกต้อง
 */
function promptPayPayload(string $id, ?float $amount = null): ?string
{
    $digits = preg_replace('/\D/', '', $id);
    $len = strlen($digits);
    if ($len === 10 && $digits[0] === '0') {
        $account = emvField('01', '0066' . substr($digits, 1));
    } elseif ($len === 13) {
        $account = emvField('02', $digits);
    } elseif ($len === 15) {
        $account = emvField('03', $digits);
    } else {
        return null;
    }

    $hasAmount = $amount !== null && $amount > 0;
    $payload = emvField('00', '01')
        . emvField('01', $hasAmount ? '12' : '11')
        . emvField('29', emvField('00', 'A000000677010111') . $account)
        . emvField('53', '764')
        . ($hasAmount ? emvField('54', number_format($amount, 2, '.', '')) : '')
        . emvField('58', 'TH')
        . '6304';
    return $payload . emvCrc16($payload);
}
