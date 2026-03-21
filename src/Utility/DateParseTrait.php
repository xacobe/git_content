<?php

namespace Drupal\git_content\Utility;

/**
 * Parses a date value (timestamp, numeric string, or date string) to a Unix timestamp.
 *
 * Classes using this trait must have $this->time (TimeInterface) available.
 */
trait DateParseTrait {

  protected function parseDate(mixed $date): int {
    if (is_int($date) || is_numeric($date)) {
      return (int) $date;
    }
    if (is_string($date)) {
      $ts = strtotime($date);
      return $ts !== FALSE ? $ts : $this->time->getCurrentTime();
    }
    return $this->time->getCurrentTime();
  }

}
