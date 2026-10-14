<?php
function isValidPastOrPresentItemDate(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }

    if ($value === "") {
        return true;
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
        return false;
    }

    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        && $value <= date("Y-m-d");
}

function isValidPastOrPresentDateRange(mixed $from, mixed $to): bool
{
    if (!isValidPastOrPresentItemDate($from) || !isValidPastOrPresentItemDate($to)) {
        return false;
    }

    $from = (string) $from;
    $to = (string) $to;

    return $from === "" || $to === "" || $from <= $to;
}
