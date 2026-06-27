<?php
/*-------------------------------------------------------
 | corebb_date_helpers.php - VN/CoreBB date conversion.
 |
 | Owns the legacy VN-style timestamp formatting helpers
 | that are still used across topic, post, PM, profile,
 | import, and admin display paths.
 +-------------------------------------------------------*/

/**
 * Usage: Convert a Unix timestamp to the stored VN/CoreBB datetime string.
 * Referenced by: import and legacy timestamp conversion code.
 *
 * @param mixed $timestamp Unix timestamp accepted by date().
 * @return string Datetime string in YYYY-M-D HH:MM:SS form.
 */
function convert_to_timestamp_raw($timestamp)
{
    // Set to the hour difference from PST when importing timestamps from another server timezone.
    $timeZone = "0";

    $datearr[5] = date("s", $timestamp - ($timeZone * 3600));
    $datearr[4] = date("i", $timestamp - ($timeZone * 3600));
    $datearr[3] = date("H", $timestamp - ($timeZone * 3600));
    $datearr[2] = date("y", $timestamp - ($timeZone * 3600));
    $datearr[1] = date("d", $timestamp - ($timeZone * 3600));
    $datearr[0] = date("n", $timestamp - ($timeZone * 3600));

    return "20" . $datearr[2] . "-" . $datearr[0] . "-" . $datearr[1] . " " . $datearr[3] . ":" . $datearr[4] . ":" . $datearr[5];
}

/**
 * Usage: Convert a VN-style display date back to a database datetime string.
 * Referenced by: archive importers and legacy edit paths.
 *
 * @param mixed $vndate VN-style date such as "6/5 1:25pm" or "1:25pm".
 * @return string Datetime string in YYYY-MM-DD HH:MM:00 form.
 */
function convert_to_timestamp($vndate)
{
    // Set to the hour difference from PST when importing timestamps from another server timezone.
    $timeZone = "0";

    $datearr = preg_split("/[\s\/:\s]+/", $vndate);

    if (count($datearr) == 2) {
        $datearr[4] = $datearr[1];
        $datearr[3] = $datearr[0];
        $datearr[2] = date("y", time() - ($timeZone * 3600));
        $datearr[1] = date("d", time() - ($timeZone * 3600));
        $datearr[0] = date("n", time() - ($timeZone * 3600));
    } elseif (count($datearr) == 4) {
        $datearr[4] = $datearr[3];
        $datearr[3] = $datearr[2];
        $datearr[2] = date("y", time() - ($timeZone * 3600));
        $datearr[1] = $datearr[1];
        $datearr[0] = $datearr[0];
    }

    for ($d = 0; $d < count($datearr); $d++) {
        if (substr($datearr[$d], strlen($datearr[$d]) - 1, 1) == "m" && strlen($datearr[$d]) != 2) {
            $datearr[$d + 1] = substr($datearr[$d], strlen($datearr[$d]) - 2, 2);
            $datearr[$d] = substr($datearr[$d], 0, strlen($datearr[$d]) - 2);
        }
    }

    if ($datearr[5] == "pm") {
        if ($datearr[3] != 12) {
            $datearr[3] = $datearr[3] + 12;
        }
    } else {
        if ($datearr[3] == 12) {
            $datearr[3] = 0;
        }
    }

    if ($datearr[0] < 10) {
        $datearr[0] = "0" . $datearr[0];
    }
    if ($datearr[1] < 10) {
        $datearr[1] = "0" . $datearr[1];
    }
    if ($datearr[3] < 10) {
        $datearr[3] = "0" . $datearr[3];
    }

    return "20" . $datearr[2] . "-" . $datearr[0] . "-" . $datearr[1] . " " . $datearr[3] . ":" . $datearr[4] . ":00";
}

/**
 * Usage: Convert a database datetime into the short VN-style display date.
 * Referenced by: legacy topic, post, PM, and profile display code.
 *
 * @param mixed $timestamp Datetime string accepted by strtotime().
 * @return string Short date/time label for the current year/day context.
 */
function convert_to_vndate($timestamp)
{
    $timestamp = strtotime($timestamp);
    $timeZone = "0";

    if (date("y", time() - ($timeZone * 3600)) != date("y", $timestamp)) {
        return date("n/j/y g:ia", $timestamp);
    }
    if (
        date("y", time() - ($timeZone * 3600)) == date("y", $timestamp)
        && date("n", time() - ($timeZone * 3600)) == date("n", $timestamp)
        && date("d", time() - ($timeZone * 3600)) == date("d", $timestamp)
    ) {
        return date("g:ia", $timestamp);
    }
    return date("n/j g:ia", $timestamp);
}
