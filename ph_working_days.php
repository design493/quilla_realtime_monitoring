<?php
/**
 * Philippine Working Days Helper
 * - Skips Saturday & Sunday
 * - Skips Philippine public holidays (Regular + Special Non-Working)
 */

function getPHHolidays(int $year): array {
    // Fixed holidays (month-day)
    $fixed = [
        '01-01', // New Year's Day
        '02-25', // EDSA People Power Revolution (Special)
        '04-09', // Araw ng Kagitingan (Bataan & Corregidor Day)
        '05-01', // Labor Day
        '06-12', // Independence Day
        '08-21', // Ninoy Aquino Day
        '08-26', // National Heroes Day (last Monday Aug — computed below, overridden)
        '11-01', // All Saints' Day (Special)
        '11-02', // All Souls' Day (Special)
        '11-30', // Bonifacio Day
        '12-08', // Feast of the Immaculate Conception (Special)
        '12-24', // Christmas Eve (Special)
        '12-25', // Christmas Day
        '12-30', // Rizal Day
        '12-31', // Last Day of the Year (Special)
    ];

    $holidays = [];

    // Add fixed holidays
    foreach ($fixed as $md) {
        $holidays[] = "$year-$md";
    }

    // National Heroes Day = last Monday of August
    $lastMonAug = date('Y-m-d', strtotime("last monday of august $year"));
    // Remove the placeholder '08-26' and add real last Monday
    $holidays = array_filter($holidays, fn($d) => $d !== "$year-08-26");
    $holidays[] = $lastMonAug;

    // Maundy Thursday & Good Friday (Easter-based)
    $easter = easter_date($year); // returns Unix timestamp of Easter Sunday
    $holidays[] = date('Y-m-d', $easter - 3 * 86400); // Maundy Thursday
    $holidays[] = date('Y-m-d', $easter - 2 * 86400); // Good Friday
    $holidays[] = date('Y-m-d', $easter - 1 * 86400); // Black Saturday (Special)

    // Eid'l Fitr and Eid'l Adha — approximate; PH govt announces exact dates yearly.
    // We include approximate dates. For production use, update these yearly.
    $eidAlFitr = [
        2024 => '2024-04-10',
        2025 => '2025-03-31',
        2026 => '2026-03-20',
        2027 => '2027-03-10',
    ];
    $eidAlAdha = [
        2024 => '2024-06-17',
        2025 => '2025-06-07',
        2026 => '2026-05-27',
        2027 => '2027-05-17',
    ];
    if (isset($eidAlFitr[$year])) $holidays[] = $eidAlFitr[$year];
    if (isset($eidAlAdha[$year])) $holidays[] = $eidAlAdha[$year];

    return array_values(array_unique($holidays));
}

/**
 * Count working days (Mon–Fri, excluding PH holidays) between today and deadline.
 * Returns 0 if deadline is in the past.
 */
function countWorkingDaysUntilDeadline(string $deadlineDate): int {
    $today    = new DateTime(date('Y-m-d'));
    $deadline = new DateTime($deadlineDate);

    if ($deadline < $today) return 0;

    // Gather holidays for all years in range
    $startYear = (int)$today->format('Y');
    $endYear   = (int)$deadline->format('Y');
    $holidays  = [];
    for ($y = $startYear; $y <= $endYear; $y++) {
        $holidays = array_merge($holidays, getPHHolidays($y));
    }
    $holidaySet = array_flip($holidays); // for O(1) lookup

    $count   = 0;
    $current = clone $today;
    // Start counting from TOMORROW (today is not a "remaining" day)
    $current->modify('+1 day');

    while ($current <= $deadline) {
        $dow  = (int)$current->format('N'); // 1=Mon … 7=Sun
        $ymd  = $current->format('Y-m-d');
        if ($dow < 6 && !isset($holidaySet[$ymd])) { // Mon–Fri, not a holiday
            $count++;
        }
        $current->modify('+1 day');
    }

    return $count;
}
