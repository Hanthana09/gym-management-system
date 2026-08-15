<?php

namespace App\Attendance;

/** Thrown by AttendanceService::checkOut() when the member has no open (checkOut === null) session today to close. */
class NoActiveSessionException extends \RuntimeException
{
}
